<?php
/**
 * Main admin class: menu and subpages, page rendering, and asset loading.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Plugin' ) ) {

	/**
	 * Main admin class.
	 */
	class YTF_Plugin {

		/**
		 * Scanning engine
		 */
		private $engine;

		/**
		 * Settings store: saves and validates settings.
		 */
		private $settings;

		/**
		 * REST routes and handlers.
		 */
		private $rest;

		/**
		 * Hook suffixes of all plugin pages, used to add the assets.
		 */
		private $page_hooks = array();

		/**
		 * Admin hooks and the REST routes, the engine, the
		 * settings store, and the REST controller.
		 */
		public function __construct() {
			$this->settings = new YTF_Settings();
			$this->engine   = new YTF_Scanner( $this->settings );
			$this->rest     = new YTF_Rest_Controller( $this->engine, $this->settings );

			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
			add_action( 'admin_menu', array( $this, 'add_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );

			add_filter( 'plugin_action_links_' . plugin_basename( YTF_PLUGIN_FILE ), array( $this, 'settings_link' ) );
		}

		/**
		 * Load the plugin translations.
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'youtube-forge', false, dirname( plugin_basename( YTF_PLUGIN_FILE ) ) . '/languages' );
		}

		/**
		 * Settings link for the plugin row action links.
		 */
		public function settings_link( $links ) {
			$url = admin_url( 'admin.php?page=ytf_settings' );
			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'youtube-forge' ) . '</a>' );
			return $links;
		}

		/**
		 * Add the top level menu and its subpages.
		 */
		public function add_menu() {
			$this->page_hooks[] = add_menu_page(
				'YouTube Forge',
				'YouTube Forge',
				'manage_options',
				'youtube_forge',
				array( $this, 'scan_page' ),
				'dashicons-video-alt3',
				80
			);

			$this->page_hooks[] = add_submenu_page( 'youtube_forge', __( 'Scanner', 'youtube-forge' ), __( 'Scanner', 'youtube-forge' ), 'manage_options', 'youtube_forge', array( $this, 'scan_page' ) );

			$this->page_hooks[] = add_submenu_page( 'youtube_forge', __( 'Scan Logs', 'youtube-forge' ), __( 'Logs', 'youtube-forge' ), 'manage_options', 'ytf_logs', array( $this, 'logs_page' ) );

			$this->page_hooks[] = add_submenu_page( 'youtube_forge', __( 'Settings', 'youtube-forge' ), __( 'Settings', 'youtube-forge' ), 'manage_options', 'ytf_settings', array( $this, 'settings_page' ) );
		}

		/**
		 * Render the scan page: a Scan button, live log, and the report.
		 */
		public function scan_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$this->engine->purge_stale_state();

			$ytf_scan_note = $this->engine->is_ready()
				? ''
				: __( 'No YouTube Data API key is saved, so nothing can be checked yet. Save one on the Settings page.', 'youtube-forge' );

			include( plugin_dir_path( YTF_PLUGIN_FILE ) . 'templates/scan-page.php' );
		}

		/**
		 * Render the Settings page: the YouTube API key and the post types to scan.
		 */
		public function settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$ytf_key_mask   = $this->settings->key_mask();
			$ytf_post_types = get_post_types( array( 'public' => true ), 'objects' );
			$ytf_scan_types = $this->settings->post_types();
			include( plugin_dir_path( YTF_PLUGIN_FILE ) . 'templates/settings-page.php' );
		}

		/**
		 * Render the scan logs page: each finished scan as a collapsible report.
		 */
		public function logs_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'youtube-forge' ) );
			}

			echo '<div class="wrap"><h1>' . esc_html__( 'Scan Logs', 'youtube-forge' ) . '</h1>';

			$history = $this->engine->get_history();
			if ( empty( $history ) ) {
				echo '<p>' . esc_html__( 'No scans yet.', 'youtube-forge' ) . '</p></div>';
				return;
			}

			echo '<p><button type="button" id="ytf-clear-logs" class="button">' . esc_html__( 'Clear Logs', 'youtube-forge' ) . '</button></p>';
			echo '<div id="ytf-logs-list">';

			foreach ( array_keys( $history ) as $i => $index ) {
				$snapshot = $history[ $index ];

				$when     = ! empty( $snapshot['finished'] ) ? wp_date( 'Y-m-d H:i:s', $snapshot['finished'] ) : '';
				$is_cli   = ( isset( $snapshot['origin'] ) && 'cli' === $snapshot['origin'] );
				$template = $is_cli
					? esc_html__( 'Scan via WP-CLI finished %1$s. %2$d posts, %3$d checked, %4$d broken.', 'youtube-forge' )
					: esc_html__( 'Scan finished %1$s. %2$d posts, %3$d checked, %4$d broken.', 'youtube-forge' );
				$summary = sprintf(
					$template,
					esc_html( $when ),
					(int) $snapshot['total'],
					(int) $snapshot['checked'],
					(int) $snapshot['broken']
				);

				$open = ( 0 === $i );

				echo '<details class="ytf-log-entry"' . ( $open ? ' open' : '' ) . '>';
				echo '<summary>' . $summary . '</summary>';
				echo '<div class="ytf-log-body" data-index="' . (int) $index . '"></div>';
				echo '</details>';

				unset( $snapshot );
			}

			echo '</div>';
			echo '</div>';
		}

		/**
		 * Enqueue the plugin styles and script on every plugin page
		 */
		public function enqueue_assets( $hook ) {
			if ( ! in_array( $hook, $this->page_hooks, true ) ) {
				return;
			}

			$css_ver = YTF_VERSION;
			$js_ver  = YTF_VERSION;

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$css_path = plugin_dir_path( YTF_PLUGIN_FILE ) . 'assets/css/admin.css';
				$js_path  = plugin_dir_path( YTF_PLUGIN_FILE ) . 'assets/js/admin.js';
				$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : YTF_VERSION;
				$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : YTF_VERSION;
			}

			wp_enqueue_style(
				'ytf-admin',
				plugins_url( 'assets/css/admin.css', YTF_PLUGIN_FILE ),
				array(),
				$css_ver
			);

			wp_enqueue_script(
				'ytf-admin',
				plugins_url( 'assets/js/admin.js', YTF_PLUGIN_FILE ),
				array(),
				$js_ver,
				true
			);

			wp_localize_script( 'ytf-admin', 'YTF', array(
				'restScan'      => esc_url_raw( rest_url( 'youtube-forge/v1/scan' ) ),
				'restScanState' => esc_url_raw( rest_url( 'youtube-forge/v1/scan-state' ) ),
				'restScanReport' => esc_url_raw( rest_url( 'youtube-forge/v1/scan-report' ) ),
				'restLogReport' => esc_url_raw( rest_url( 'youtube-forge/v1/log-report' ) ),
				'restTrash'     => esc_url_raw( rest_url( 'youtube-forge/v1/trash-post' ) ),
				'restTrashBulk' => esc_url_raw( rest_url( 'youtube-forge/v1/trash-posts' ) ),
				'restSetting'   => esc_url_raw( rest_url( 'youtube-forge/v1/save-setting' ) ),
				'restClearLogs' => esc_url_raw( rest_url( 'youtube-forge/v1/clear-logs' ) ),
				'restReset'     => esc_url_raw( rest_url( 'youtube-forge/v1/reset-settings' ) ),
				'restPostTypes' => esc_url_raw( rest_url( 'youtube-forge/v1/save-post-types' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'trashBatch'    => YTF_Rest_Controller::trash_batch(),
				'i18n'          => array(
					'error'        => __( 'Could not reach the server.', 'youtube-forge' ),
					'trashedLabel' => __( 'Trashed', 'youtube-forge' ),
					'deletedLabel' => __( 'Deleted', 'youtube-forge' ),
					'trashLabel'   => __( 'Trash', 'youtube-forge' ),
					'viewLabel'    => __( 'View', 'youtube-forge' ),
					'editLabel'    => __( 'Edit', 'youtube-forge' ),
					'trashRemoveOne'  => __( 'Trashing this post removes it and the 1 broken link it contains:', 'youtube-forge' ),
					'trashRemoveMany' => __( 'Trashing this post removes it and the %d broken links it contains:', 'youtube-forge' ),
					'trashError'      => __( 'Could not trash the post.', 'youtube-forge' ),
					'confirmTrashAll'  => __( 'Trash all %d posts that have a broken link in this report? This cannot be undone in bulk.', 'youtube-forge' ),
					'trashAllProgress' => __( 'Trashing %1$d of %2$d...', 'youtube-forge' ),
					'trashAllError'    => __( 'Some posts could not be trashed.', 'youtube-forge' ),
					'trashAllSkipped'  => __( '%d could not be trashed.', 'youtube-forge' ),
					'confirmClear' => __( 'Clear all saved scan logs?', 'youtube-forge' ),
					'clearError'   => __( 'Could not clear the logs.', 'youtube-forge' ),
					'noScans'      => __( 'No scans yet.', 'youtube-forge' ),
					'confirmReset' => __( 'Delete the saved YouTube Data API key?', 'youtube-forge' ),
					'resetError'   => __( 'Could not delete the API key.', 'youtube-forge' ),
					'deleteLabel'  => __( 'Delete', 'youtube-forge' ),
					'cancel'       => __( 'Cancel', 'youtube-forge' ),
					'close'        => __( 'Close', 'youtube-forge' ),
					'saving'       => __( 'Saving...', 'youtube-forge' ),
					'saved'        => __( 'Saved.', 'youtube-forge' ),
					'verified'     => __( 'verified', 'youtube-forge' ),
					'invalid'      => __( 'invalid', 'youtube-forge' ),
				),
			) );
		}

	}
}
