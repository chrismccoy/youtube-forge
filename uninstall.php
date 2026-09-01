<?php
// Fired when the plugin is deleted from the WordPress admin.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Saved YouTube Data API key.
delete_option( 'ytf_youtube_key' );

// Post types the scanner uses
delete_option( 'ytf_scan_post_types' );

// History of the last finished scans shown on the Logs page.
delete_option( 'ytf_scan_history' );

// Progress and findings of a scan that was still running.
delete_option( 'ytf_scan_state' );
delete_option( 'ytf_scan_find' );

// Where a scan that stopped on a spent API quota would have resumed from.
delete_option( 'ytf_scan_resume' );

// Mutex held while a scan runs.
delete_option( 'ytf_scan_lock' );

// Cached video results, kept as transients on sites with no persistent object cache.
global $wpdb;

foreach ( array( '_transient_ytf_verdict_', '_transient_timeout_ytf_verdict_' ) as $ytf_prefix ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $ytf_prefix ) . '%'
	) );
}
