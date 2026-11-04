<?php
/**
 * Reads and writes the running scan across its two options.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Scan_State_Store' ) ) {

	/**
	 * The running scan's storage.
	 */
	class YTF_Scan_State_Store {

		/**
		 * Option holding the scan's progress.
		 */
		const STATE_OPTION = 'ytf_scan_state';

		/**
		 * Option holding the scan's findings.
		 */
		const FIND_OPTION = 'ytf_scan_find';

		/**
		 * The values a scan's status field is allowed to hold.
		 */
		const STATUSES = array( 'running', 'done' );

		/**
		 * The keys that live in the findings option rather than the progress one.
		 */
		const FINDING_KEYS = array( 'broken_links', 'unchecked_links', 'found_links', 'errors' );

		/**
		 * Save a scan across its two options.
		 */
		public function save( $state, $findings_dirty ) {
			$findings = array();
			$progress = $state;

			foreach ( self::FINDING_KEYS as $key ) {
				$findings[ $key ] = isset( $state[ $key ] ) ? $state[ $key ] : array();
				unset( $progress[ $key ] );
			}

			update_option( self::STATE_OPTION, $progress, false );

			if ( $findings_dirty ) {
				update_option( self::FIND_OPTION, $findings, false );
			}
		}

		/**
		 * Load the scan from its two options.
		 */
		public function load() {
			$progress = $this->raw();
			if ( ! is_array( $progress ) ) {
				return null;
			}

			$status = isset( $progress['status'] ) ? (string) $progress['status'] : '';
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return false;
			}
			$progress['status'] = $status;

			$findings = get_option( self::FIND_OPTION );
			$findings = is_array( $findings ) ? $findings : array();

			foreach ( self::FINDING_KEYS as $key ) {
				$progress[ $key ] = isset( $findings[ $key ] ) ? $findings[ $key ] : array();
			}

			$progress['total']        = isset( $progress['total'] ) ? (int) $progress['total'] : 0;
			$progress['checked']      = isset( $progress['checked'] ) ? (int) $progress['checked'] : 0;
			$progress['broken']       = isset( $progress['broken'] ) ? (int) $progress['broken'] : 0;
			$progress['unchecked']    = isset( $progress['unchecked'] ) ? (int) $progress['unchecked'] : 0;
			$progress['log']          = isset( $progress['log'] ) && is_array( $progress['log'] ) ? $progress['log'] : array();
			$progress['log_offset']   = isset( $progress['log_offset'] ) ? (int) $progress['log_offset'] : 0;
			$progress['retry']        = isset( $progress['retry'] ) && is_array( $progress['retry'] ) ? $progress['retry'] : array();
			$progress['retry_rounds'] = isset( $progress['retry_rounds'] ) ? (int) $progress['retry_rounds'] : 0;
			$progress['chunksize']    = isset( $progress['chunksize'] ) ? (int) $progress['chunksize'] : YTF_BATCH_POSTS;
			$progress['lock']         = isset( $progress['lock'] ) ? (string) $progress['lock'] : '';
			$progress['fast']         = ! empty( $progress['fast'] );
			$progress['truncated']       = ! empty( $progress['truncated'] );
			$progress['found_truncated'] = ! empty( $progress['found_truncated'] );
			$progress['started']      = isset( $progress['started'] ) ? (int) $progress['started'] : 0;
			$progress['touched']      = isset( $progress['touched'] ) ? (int) $progress['touched'] : 0;
			$progress['finished']     = isset( $progress['finished'] ) ? (int) $progress['finished'] : 0;

			return $progress;
		}

		/**
		 * The stored progress option as it is, with no validation or defaults.
		 */
		public function raw() {
			return get_option( self::STATE_OPTION );
		}

		/**
		 * Whether a scan is running right now.
		 */
		public function is_running() {
			$state = $this->raw();
			return is_array( $state ) && isset( $state['status'] ) && 'running' === $state['status'];
		}

		/**
		 * Delete both scan options.
		 */
		public function delete() {
			delete_option( self::STATE_OPTION );
			delete_option( self::FIND_OPTION );
		}

	}
}
