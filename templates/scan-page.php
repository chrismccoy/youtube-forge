<?php
/**
 * Scan page template.
 */

defined( 'ABSPATH' ) or exit;
?>
<div class="wrap">

<h1><?php esc_html_e( 'YouTube Forge', 'youtube-forge' ); ?></h1>

	<p><?php esc_html_e( 'Scan your posts and post meta for YouTube links that no longer work.', 'youtube-forge' ); ?></p>

	<?php if ( ! empty( $ytf_scan_note ) ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $ytf_scan_note ); ?></p></div>
	<?php endif; ?>

	<div id="ytf-scan-section">

		<p>
			<button type="button" id="ytf-scan" class="button button-primary">
				<?php esc_html_e( 'Scan Now', 'youtube-forge' ); ?> &raquo;
			</button>
			<span id="ytf-counts"></span>
		</p>

		<p id="ytf-scan-msg" class="ytf-scan-msg"></p>

		<div id="ytf-output" style="display:none;">
			<p>
				<button type="button" id="ytf-dismiss" class="button" style="display:none;">
					<?php esc_html_e( 'Dismiss', 'youtube-forge' ); ?>
				</button>
			</p>
			<pre id="ytf-terminal" aria-live="polite"></pre>
			<div id="ytf-report"></div>
		</div>

	</div>

</div>
