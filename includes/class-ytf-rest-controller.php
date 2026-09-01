<?php
/**
 * REST routes and handlers under the youtube-forge/v1 namespace.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Rest_Controller' ) ) {

	/**
	 * Registers and handles the plugin's REST routes under youtube-forge/v1.
	 */
	class YTF_Rest_Controller {

		/**
		 * REST namespace for every route.
		 */
		const NS = 'youtube-forge/v1';

		/**
		 * Scanning engine.
		 */
		private $engine;

		/**
		 * Settings store: saves and validates settings.
		 */
		private $settings;

		public function __construct( $engine, $settings ) {
			$this->engine   = $engine;
			$this->settings = $settings;
		}

		/**
		 * Permission check shared by every route: the manage_options capability.
		 */
		public function can_manage() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Register all REST routes. Hooked on rest_api_init.
		 */
		public function register_routes() {
			$perm = array( $this, 'can_manage' );

			register_rest_route( self::NS, '/trash-post', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_trash_post' ),
				'permission_callback' => $perm,
				'args'                => array(
					'postID' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			) );

			register_rest_route( self::NS, '/trash-posts', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_trash_posts' ),
				'permission_callback' => $perm,
				'args'                => array(
					'postIDs' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			) );

			register_rest_route( self::NS, '/save-setting', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_setting' ),
				'permission_callback' => $perm,
				'args'                => array(
					'field' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'value' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			) );

			register_rest_route( self::NS, '/scan', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_scan' ),
				'permission_callback' => $perm,
				'args'                => array(
					'action' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'cursor' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			) );

			register_rest_route( self::NS, '/scan-state', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_scan_state' ),
				'permission_callback' => $perm,
				'args'                => array(
					'cursor' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			) );

			register_rest_route( self::NS, '/scan-report', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_scan_report' ),
				'permission_callback' => $perm,
			) );

			register_rest_route( self::NS, '/log-report', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_log_report' ),
				'permission_callback' => $perm,
				'args'                => array(
					'index' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			) );

			register_rest_route( self::NS, '/clear-logs', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_clear_logs' ),
				'permission_callback' => $perm,
			) );

			register_rest_route( self::NS, '/reset-settings', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_reset_settings' ),
				'permission_callback' => $perm,
			) );

			register_rest_route( self::NS, '/save-post-types', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_post_types' ),
				'permission_callback' => $perm,
				'args'                => array(
					'types' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
					),
				),
			) );
		}

		/**
		 * How many posts one bulk trash request handles.
		 */
		public static function trash_batch() {
			return max( 1, (int) apply_filters( 'ytf_trash_batch', YTF_TRASH_BATCH ) );
		}

		/**
		 * Move a post to the trash after a per post capability check.
		 */
		public function rest_trash_post( $request ) {
			$post_id = (int) $request->get_param( 'postID' );

			if ( ! $post_id || ! current_user_can( 'delete_post', $post_id ) ) {
				return new WP_REST_Response( array(
					'ok'      => false,
					'message' => __( 'You are not allowed to trash this post.', 'youtube-forge' ),
				), 403 );
			}

			if ( ! wp_trash_post( $post_id ) ) {
				return new WP_REST_Response( array(
					'ok'      => false,
					'message' => __( 'Could not trash the post.', 'youtube-forge' ),
				), 500 );
			}

			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		/**
		 * Trash a batch of posts. Each post is capability checked on its own.
		 */
		public function rest_trash_posts( $request ) {
			$ids = array_map( 'absint', (array) $request->get_param( 'postIDs' ) );
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			$ids = array_slice( $ids, 0, self::trash_batch() );

			YTF_Scanner::prime_posts( $ids, false );

			$trashed = array();
			$skipped = 0;
			foreach ( $ids as $id ) {
				if ( ! current_user_can( 'delete_post', $id ) ) {
					$skipped++;
					continue;
				}
				if ( wp_trash_post( $id ) ) {
					$trashed[] = $id;
				} else {
					$skipped++;
				}
			}

			return new WP_REST_Response( array(
				'ok'      => true,
				'trashed' => $trashed,
				'skipped' => $skipped,
			), 200 );
		}

		/**
		 * Save a settings field (the YouTube API key).
		 */
		public function rest_save_setting( $request ) {
			$field = sanitize_key( (string) $request->get_param( 'field' ) );
			$value = (string) $request->get_param( 'value' );
			return $this->settings->save_field( $field, $value );
		}

		/**
		 * Save the post types to scan.
		 */
		public function rest_save_post_types( $request ) {
			return $this->settings->save_post_types( (array) $request->get_param( 'types' ) );
		}

		/**
		 * Reset the saved API key.
		 */
		public function rest_reset_settings() {
			return $this->settings->reset();
		}

		/**
		 * Clear the saved scan history.
		 */
		public function rest_clear_logs() {
			$this->engine->clear_history();
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		/**
		 * The Scan.
		 */
		public function rest_scan( $request ) {
			$action = (string) $request->get_param( 'action' );
			$cursor = (int) $request->get_param( 'cursor' );

			if ( 'start' === $action ) {
				if ( ! $this->engine->is_ready() ) {
					return new WP_REST_Response( array(
						'error' => __( 'Save a valid YouTube Data API key on the Settings page before scanning.', 'youtube-forge' ),
					), 400 );
				}

				$state = $this->engine->start_scan();

				if ( is_wp_error( $state ) ) {
					return new WP_REST_Response( array( 'error' => $state->get_error_message() ), 409 );
				}
			} elseif ( 'next' === $action ) {
				$state = $this->engine->advance( $cursor );
			} else {
				$state = $this->engine->get_state();
			}

			return new WP_REST_Response( $this->scan_payload( $state, $cursor ), 200 );
		}

		/**
		 * Read the current scan without advancing it.
		 */
		public function rest_scan_state( $request ) {
			$cursor = (int) $request->get_param( 'cursor' );
			return new WP_REST_Response( $this->scan_payload( $this->engine->get_state(), $cursor ), 200 );
		}

		/**
		 * Return one saved scan's report from the history, as data.
		 */
		public function rest_log_report( $request ) {
			return new WP_REST_Response( array(
				'ok'     => true,
				'report' => $this->engine->history_report_data( (int) $request->get_param( 'index' ) ),
			), 200 );
		}

		/**
		 * Return the finished scan's report as data and clear the result.
		 */
		public function rest_scan_report() {
			return new WP_REST_Response( array(
				'ok'     => true,
				'report' => $this->engine->take_report(),
			), 200 );
		}

		/**
		 * Result from a scan into JSON the page expects. Returns only the
		 * log lines, and the report once done.
		 */
		private function scan_payload( $state, $cursor ) {
			if ( ! is_array( $state ) ) {
				return array(
					'status'  => 'idle',
					'total'   => 0,
					'checked' => 0,
					'broken'  => 0,
					'log'     => array(),
					'cursor'  => 0,
					'done'    => false,
					'report'  => false,
				);
			}

			$log    = isset( $state['log'] ) ? $state['log'] : array();
			$offset = isset( $state['log_offset'] ) ? (int) $state['log_offset'] : 0;
			$from   = max( 0, $cursor - $offset );
			$new    = ( $from < count( $log ) ) ? array_slice( $log, $from ) : array();
			$done   = ( 'done' === $state['status'] );

			if ( $cursor < $offset ) {
				array_unshift( $new, sprintf(
					__( '... %d earlier lines are no longer available.', 'youtube-forge' ),
					$offset - $cursor
				) );
			}

			return array(
				'status'    => $state['status'],
				'total'     => (int) $state['total'],
				'checked'   => (int) $state['checked'],
				'broken'    => (int) $state['broken'],
				'unchecked' => isset( $state['unchecked'] ) ? (int) $state['unchecked'] : 0,
				'log'       => array_values( $new ),
				'cursor'    => $offset + count( $log ),
				'done'      => $done,
				'report'    => $done,
			);
		}

	}
}
