<?php
/*
Plugin Name: YouTube Forge
Plugin URI: https://github.com/chrismccoy/youtube-forge
Description: Scans posts and post meta for broken YouTube links using the official YouTube Data API and reports them.
Version: 1.0.0
Author: Chris McCoy
Author URI: https://github.com/chrismccoy
Text Domain: youtube-forge
Requires at least: 6.6
Requires PHP: 8.3
*/

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! defined( 'YTF_VERSION' ) ) {
	define( 'YTF_VERSION', '1.0.0' );
}

if ( ! defined( 'YTF_PLUGIN_FILE' ) ) {
	define( 'YTF_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'YTF_BATCH_POSTS' ) ) {
	define( 'YTF_BATCH_POSTS', 50 );
}

if ( ! defined( 'YTF_MAX_REPORT_ROWS' ) ) {
	define( 'YTF_MAX_REPORT_ROWS', 250 );
}

if ( ! defined( 'YTF_MAX_REPORT_ERRORS' ) ) {
	define( 'YTF_MAX_REPORT_ERRORS', 50 );
}

if ( ! defined( 'YTF_MAX_LOG_LINES' ) ) {
	define( 'YTF_MAX_LOG_LINES', 2000 );
}

if ( ! defined( 'YTF_LOG_WINDOW' ) ) {
	define( 'YTF_LOG_WINDOW', 1000 );
}

if ( ! defined( 'YTF_TRASH_BATCH' ) ) {
	define( 'YTF_TRASH_BATCH', 50 );
}

if ( ! defined( 'YTF_MAX_HISTORY_ROWS' ) ) {
	define( 'YTF_MAX_HISTORY_ROWS', 100 );
}

if ( ! defined( 'YTF_API_TIMEOUT' ) ) {
	define( 'YTF_API_TIMEOUT', 5 );
}

if ( ! defined( 'YTF_CHUNK_SECONDS' ) ) {
	define( 'YTF_CHUNK_SECONDS', 15 );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-error-log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-link.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-link-extractor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-scan-report.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-scan-state-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-scan-lock.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-scan-history.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-scan-resume.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-scanner.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-rest-controller.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-plugin.php';

// WP-CLI command: wp youtube-forge scan.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-ytf-cli.php';
	WP_CLI::add_command( 'youtube-forge', 'YTF_CLI' );
}

if ( class_exists( 'YTF_Plugin' ) ) {
	$GLOBALS['ytf_plugin'] = new YTF_Plugin();
}
