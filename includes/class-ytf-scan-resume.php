<?php
/**
 * Resume a scan that stopped early
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Scan_Resume' ) ) {

	/**
	 * The resume point.
	 */
	class YTF_Scan_Resume {

		/**
		 * Option holding where a scan that stopped
		 */
		const OPTION = 'ytf_scan_resume';

		/**
		 * How long a saved resume stays usable.
		 */
		const MAX_AGE = WEEK_IN_SECONDS;

		/**
		 * Settings store the fallback post types come from.
		 */
		private $settings;

		public function __construct( $settings = null ) {
			$this->settings = ( $settings instanceof YTF_Settings ) ? $settings : new YTF_Settings();
		}

		/**
		 * The post types a scan covers
		 */
		public function scope_post_types( $state ) {
			$types = ( ! empty( $state['post_types'] ) && is_array( $state['post_types'] ) )
				? $state['post_types']
				: $this->settings->post_types();

			$types = array_values( array_unique( $types ) );
			sort( $types );

			return $types;
		}

		/**
		 * Save where a scan that stopped early
		 */
		public function save( $state ) {
			if ( empty( $state['after_id'] ) ) {
				return;
			}

			update_option( self::OPTION, array(
				'after_id'    => (int) $state['after_id'],
				'total'       => (int) $state['total'],
				'post_types'  => $this->scope_post_types( $state ),
				'post_status' => isset( $state['post_status'] ) ? $state['post_status'] : 'publish',
				'saved'       => time(),
			), false );
		}

		/**
		 * The saved resume point
		 */
		public function matching( $state ) {
			$resume = get_option( self::OPTION );
			if ( ! is_array( $resume ) || empty( $resume['after_id'] ) ) {
				return null;
			}

			$saved = isset( $resume['saved'] ) ? (int) $resume['saved'] : 0;
			if ( ( time() - $saved ) > self::MAX_AGE ) {
				$this->clear();
				return null;
			}

			$saved_types = isset( $resume['post_types'] ) && is_array( $resume['post_types'] ) ? $resume['post_types'] : array();
			sort( $saved_types );

			$same_types  = ( $saved_types === $this->scope_post_types( $state ) );
			$same_status = ( isset( $resume['post_status'] ) ? $resume['post_status'] : 'publish' ) === $state['post_status'];

			return ( $same_types && $same_status ) ? $resume : null;
		}

		/**
		 * Drop the saved resume point.
		 */
		public function clear() {
			delete_option( self::OPTION );
		}

	}
}
