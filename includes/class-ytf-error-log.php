<?php
/**
 * Collects timestamped errors shared across the scanner and the API client.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Error_Log' ) ) {

	/**
	 * Collects timestamped error messages during a scan. Shared with the
	 * extractor and the API client as their logger.
	 */
	class YTF_Error_Log {

		/**
		 * Totalerror lines.
		 */
		private $errors = array();

		/**
		 * Last formatted timestamp
		 */
		private $stamp = array();

		/**
		 * Current site time formatted for an error line.
		 */
		private function now() {
			$ts = time();

			if ( ! isset( $this->stamp[ $ts ] ) ) {
				$this->stamp = array( $ts => wp_date( 'Y-m-d H:i:s', $ts ) );
			}

			return $this->stamp[ $ts ];
		}

		/**
		 * Record one error message with a timestamp. Stored as plain text
		 */
		public function add_error( $msg ) {
			$this->errors[] = $this->now() . ': ' . wp_strip_all_tags( (string) $msg );
		}

		/**
		 * Return the raw error lines, for merging into the scan
		 */
		public function all() {
			return $this->errors;
		}

		/**
		 * Return the error lines joined into one plain text string.
		 */
		public function render() {
			return implode( "\n", $this->errors );
		}

	}
}
