<?php
/**
 * Saves and validates settings: the YouTube API key, post types, masking, reset.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Settings' ) ) {

	/**
	 * Plugin settings: the YouTube Data API key and the post types to scan.
	 */
	class YTF_Settings {

		/**
		 * Option holding the YouTube Data API key.
		 */
		const KEY_OPTION = 'ytf_youtube_key';

		/**
		 * Option holding the post types to scan, as a list of slugs.
		 */
		const POST_TYPES_OPTION = 'ytf_scan_post_types';

		/**
		 * The post type scanned when nothing else is saved.
		 */
		const DEFAULT_POST_TYPE = 'post';

		/**
		 * The saved YouTube Data API key, trimmed.
		 */
		public function api_key() {
			return trim( (string) get_option( self::KEY_OPTION, '' ) );
		}

		/**
		 * Whether an API key is saved, which is what makes a scan possible.
		 */
		public function has_api_key() {
			return '' !== $this->api_key();
		}

		/**
		 * Post types to scan. Defaults to just "post" when nothing is saved.
		 * Only registered types are returned.
		 */
		public function post_types() {
			$saved = get_option( self::POST_TYPES_OPTION );
			if ( ! is_array( $saved ) || empty( $saved ) ) {
				return array( self::DEFAULT_POST_TYPE );
			}

			$valid = array();
			foreach ( $saved as $type ) {
				$type = sanitize_key( $type );
				if ( '' !== $type && post_type_exists( $type ) ) {
					$valid[] = $type;
				}
			}

			return empty( $valid ) ? array( self::DEFAULT_POST_TYPE ) : $valid;
		}

		/**
		 * Masked preview of the saved API key.
		 */
		public function key_mask() {
			return $this->mask_key( $this->api_key() );
		}

		/**
		 * Build a masked preview of a key: first four and last two characters,
		 * with the middle hidden. Short keys are fully masked.
		 */
		public function mask_key( $key ) {
			$key = (string) $key;
			$len = strlen( $key );
			if ( 0 === $len ) {
				return '';
			}
			if ( $len <= 6 ) {
				return str_repeat( '•', $len );
			}
			return substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -2 );
		}

		/**
		 * Save a settings field after verifying it.
		 */
		public function save_field( $field, $value ) {
			if ( 'youtube_key' === $field ) {
				return $this->save_youtube_key( $value );
			}
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Unknown setting.', 'youtube-forge' ) ), 400 );
		}

		/**
		 * Validate and save the YouTube Data API key. An empty submit keeps the saved key.
		 */
		public function save_youtube_key( $value ) {
			$value = sanitize_text_field( trim( $value ) );
			$saved = $this->api_key();

			if ( '' === $value && '' !== $saved ) {
				return new WP_REST_Response( array(
					'ok'        => true,
					'message'   => '',
					'valueMask' => $this->mask_key( $saved ),
					'statuses'  => array(),
				), 200 );
			}

			$message = '';
			if ( '' !== $value ) {
				$client = new YTF_Api_Client( new YTF_Error_Log(), $this );
				$result = $client->validate_key( $value );
				if ( empty( $result['valid'] ) ) {
					return new WP_REST_Response( array(
						'ok'       => false,
						'error'    => isset( $result['message'] ) ? $result['message'] : __( 'The API rejected this key.', 'youtube-forge' ),
						'statuses' => array( 'YouTube Data API' => false ),
					), 200 );
				}
				$message = isset( $result['message'] ) ? $result['message'] : '';
			}

			update_option( self::KEY_OPTION, $value, false );

			return new WP_REST_Response( array(
				'ok'        => true,
				'message'   => $message,
				'valueMask' => $this->mask_key( $value ),
				'statuses'  => ( '' !== $value ) ? array( 'YouTube Data API' => true ) : array(),
			), 200 );
		}

		/**
		 * Save the post types to scan. Only registered public types are kept.
		 */
		public function save_post_types( $requested ) {
			$requested = (array) $requested;

			$public = get_post_types( array( 'public' => true ) );

			$valid = array();
			foreach ( $requested as $type ) {
				$type = sanitize_key( $type );
				if ( '' !== $type && isset( $public[ $type ] ) ) {
					$valid[ $type ] = $type;
				}
			}

			$valid = array_values( $valid );

			if ( empty( $valid ) ) {
				$valid = array( self::DEFAULT_POST_TYPE );
			}

			update_option( self::POST_TYPES_OPTION, $valid, false );

			return new WP_REST_Response( array( 'ok' => true, 'types' => $valid ), 200 );
		}

		/**
		 * Delete the saved API key.
		 */
		public function reset() {
			delete_option( self::KEY_OPTION );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

	}
}
