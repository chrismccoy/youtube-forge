<?php
/**
 * The mutex that stops two scans running at once.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Scan_Lock' ) ) {

	/**
	 * The scan lock. WordPress has no lock API, so the unique index on
	 * option_name is used
	 */
	class YTF_Scan_Lock {

		/**
		 * Option used as the mutex.
		 */
		const OPTION = 'ytf_scan_lock';

		/**
		 * How long a held lock before expiring
		 */
		const TTL = HOUR_IN_SECONDS;

		/**
		 * How long a lock must be held, with no scan running
		 */
		const SETTLE = 30;

		/**
		 * Whether a scan is running right now.
		 */
		private $is_running;

		/**
		 * The token this instance holds, or an empty string when it holds none.
		 */
		private $token = '';

		public function __construct( $is_running = null ) {
			$this->is_running = $is_running;
		}

		/**
		 * Whether a scan is running, according to the injected check.
		 */
		private function scan_is_running() {
			return is_callable( $this->is_running ) ? (bool) call_user_func( $this->is_running ) : false;
		}

		/**
		 * The token this instance holds, for storing with the scan it belongs to.
		 */
		public function token() {
			return $this->token;
		}

		/**
		 * Take ownership of a token read back from a scan state, so a later
		 * request in the same run can release the lock it started.
		 */
		public function adopt( $token ) {
			$token = (string) $token;
			if ( '' !== $token ) {
				$this->token = $token;
			}
		}

		/**
		 * A token identifying one run.
		 */
		private function new_token() {
			return wp_generate_password( 16, false, false );
		}

		/**
		 * Build the stored value for a token and a time.
		 */
		private function encode( $token, $time ) {
			return $token . ':' . (int) $time;
		}

		/**
		 * The lock as stored: its raw value, token, and timestamp.
		 */
		private function held() {
			$raw = get_option( self::OPTION );
			if ( false === $raw ) {
				return null;
			}

			$raw   = (string) $raw;
			$parts = explode( ':', $raw, 2 );

			if ( 2 === count( $parts ) ) {
				return array(
					'raw'   => $raw,
					'token' => $parts[0],
					'time'  => (int) $parts[1],
				);
			}

			return array(
				'raw'   => $raw,
				'token' => '',
				'time'  => (int) $raw,
			);
		}

		/**
		 * Whether a held lock has been abandoned and may be taken.
		 */
		private function is_abandoned( $held ) {
			$age = time() - (int) $held['time'];
			$ttl = (int) apply_filters( 'ytf_scan_lock_ttl', self::TTL );

			if ( $age > $ttl ) {
				return true;
			}

			return ( $age > self::SETTLE && ! $this->scan_is_running() );
		}

		/**
		 * Take the scan lock, or report that somebody else holds it.
		 */
		public function claim() {
			$token = $this->new_token();

			$this->insert( $token, time() );
			$held = $this->held();

			if ( null === $held ) {
				$this->insert( $token, time() );
				$held = $this->held();
				if ( null === $held ) {
					return false;
				}
			}

			if ( $held['token'] === $token ) {
				$this->token = $token;
				return true;
			}

			if ( ! $this->is_abandoned( $held ) ) {
				return false;
			}

			if ( ! $this->replace( $held['raw'], $this->encode( $token, time() ) ) ) {
				return false;
			}

			$this->token = $token;
			return true;
		}

		/**
		 * Try to create the lock row.
		 */
		private function insert( $token, $time ) {
			global $wpdb;

			$result = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				self::OPTION,
				$this->encode( $token, $time )
			) );

			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );

			return $result;
		}

		/**
		 * Compare and set the lock row
		 */
		private function replace( $expected, $value ) {
			global $wpdb;

			$taken = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				(string) $expected
			) );

			wp_cache_delete( self::OPTION, 'options' );

			return ( $taken > 0 );
		}

		/**
		 * Release the lock
		 */
		public function release() {
			global $wpdb;

			if ( '' === $this->token ) {
				return false;
			}

			$held = $this->held();
			if ( null !== $held && $held['token'] !== $this->token ) {
				$this->token = '';
				return false;
			}

			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				self::OPTION,
				$wpdb->esc_like( $this->token . ':' ) . '%'
			) );

			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );

			$this->token = '';

			return ( $deleted > 0 );
		}

	}
}
