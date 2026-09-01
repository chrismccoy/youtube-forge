<?php
/**
 * Settings page template.
 */

defined( 'ABSPATH' ) or exit;
?>
<div class="wrap">

<h1><?php esc_html_e( 'Settings', 'youtube-forge' ); ?></h1>

	<div class="ytf-settings-card">
		<h2><?php esc_html_e( 'YouTube Data API', 'youtube-forge' ); ?></h2>
		<p><?php esc_html_e( 'Enter and save your YouTube Data API key. It is checked against the API before it is saved. Without a key nothing can be scanned.', 'youtube-forge' ); ?></p>
		<p>
			<input type="text" id="ytf-key" size="55" autocomplete="off" value=""
				placeholder="<?php echo $ytf_key_mask ? esc_attr__( 'Enter a new key to replace', 'youtube-forge' ) : esc_attr__( 'YouTube Data API key', 'youtube-forge' ); ?>" />
			<button type="button" id="ytf-key-save" class="button button-primary" data-field="youtube_key"><?php esc_html_e( 'Save and Verify', 'youtube-forge' ); ?></button>
		</p>
		<p id="ytf-key-saved" class="ytf-saved" data-label="<?php echo esc_attr__( 'Saved key:', 'youtube-forge' ); ?>" data-none="<?php echo esc_attr__( 'No API key saved.', 'youtube-forge' ); ?>">
			<?php echo $ytf_key_mask ? esc_html( sprintf( __( 'Saved key: %s', 'youtube-forge' ), $ytf_key_mask ) ) : esc_html__( 'No API key saved.', 'youtube-forge' ); ?>
		</p>
		<p id="ytf-key-status" class="ytf-setting-status"></p>
		<div id="ytf-key-health"></div>

		<hr />
		<p>
			<button type="button" id="ytf-reset" class="button"><?php esc_html_e( 'Delete API Key', 'youtube-forge' ); ?></button>
		</p>
		<p id="ytf-reset-status" class="ytf-setting-status"></p>
	</div>

	<div class="ytf-settings-card">
		<h2><?php esc_html_e( 'Post types to scan', 'youtube-forge' ); ?></h2>
		<p><?php esc_html_e( 'Choose which post types are scanned for links. Applies to every scan, including WP-CLI. Defaults to Posts.', 'youtube-forge' ); ?></p>
		<div id="ytf-post-types" class="ytf-post-types">
			<?php foreach ( $ytf_post_types as $ytf_pt ) : ?>
				<label class="ytf-pt-item">
					<input type="checkbox" class="ytf-pt" value="<?php echo esc_attr( $ytf_pt->name ); ?>" <?php checked( in_array( $ytf_pt->name, $ytf_scan_types, true ) ); ?> />
					<?php echo esc_html( $ytf_pt->labels->singular_name ); ?>
					<code><?php echo esc_html( $ytf_pt->name ); ?></code>
				</label>
			<?php endforeach; ?>
		</div>
		<p>
			<button type="button" id="ytf-pt-save" class="button button-primary"><?php esc_html_e( 'Save Post Types', 'youtube-forge' ); ?></button>
			<button type="button" id="ytf-pt-reset" class="button"><?php esc_html_e( 'Reset', 'youtube-forge' ); ?></button>
		</p>
		<p id="ytf-pt-status" class="ytf-setting-status"></p>
	</div>

</div>
