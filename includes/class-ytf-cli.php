<?php
/**
 * WP-CLI command: wp youtube-forge scan.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_CLI' ) ) {

	/**
	 * WP-CLI commands, registered as `wp youtube-forge`. Runs the same scanner
	 * the admin UI uses, but inline and to the terminal. Results print as a table
	 * by default; csv, json, and yaml are also available with --format.
	 */
	class YTF_CLI {

		/**
		 * Scan posts for broken YouTube links and print the results.
		 *
		 * ## OPTIONS
		 *
		 * [--post-status=<status>]
		 * : Post status to scan. Defaults to publish.
		 * ---
		 * default: publish
		 * ---
		 *
		 * [--post-type=<types>]
		 * : Comma separated post types to scan, for this run only. Overrides the
		 * Post types to scan setting without changing it. Defaults to the setting.
		 *
		 * [--limit=<number>]
		 * : Stop after scanning this many posts. 0 (the default) scans all.
		 * ---
		 * default: 0
		 * ---
		 *
		 * [--found]
		 * : Also include working (found) links in the output, not just broken ones.
		 *
		 * [--fast]
		 * : Only ask whether each video still exists, halving the response size.
		 * Missing, removed, and private videos are still reported as broken, but a
		 * video that exists and is blocked from embedding reads as working. Fast
		 * and full results are cached separately, so this never affects a later
		 * full scan.
		 *
		 * [--trash]
		 * : After scanning, move every post that has a broken link to the trash.
		 * Prompts for confirmation first (this is destructive). Pass --yes to skip
		 * the prompt, or --dry-run to preview without trashing.
		 *
		 * [--dry-run]
		 * : With --trash, list how many posts would be trashed but change nothing.
		 *
		 * [--format=<format>]
		 * : Output format for the results.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 *   - yaml
		 *   - count
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # Scan and print a table of broken links.
		 *     $ wp youtube-forge scan
		 *
		 *     # Scan the first 200 posts, including working links, as CSV.
		 *     $ wp youtube-forge scan --limit=200 --found --format=csv > links.csv
		 *
		 *     # Scan and trash every post with a broken link (prompts first).
		 *     $ wp youtube-forge scan --trash
		 *
		 *     # The cheap check, for a first pass over a very large site.
		 *     $ wp youtube-forge scan --fast
		 */
		public function scan( $args, $assoc_args ) {
			$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
			$found  = isset( $assoc_args['found'] );

			$engine = new YTF_Scanner();

			if ( ! $engine->is_ready() ) {
				WP_CLI::error( 'No YouTube Data API key is saved. Save one on the Settings page first.' );
			}

			$opts = array(
				'post_status' => isset( $assoc_args['post-status'] ) ? $assoc_args['post-status'] : 'publish',
				'max_posts'   => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
				'keep_found'  => $found,
				'fast'        => isset( $assoc_args['fast'] ),
				'origin'      => 'cli',
			);

			if ( isset( $assoc_args['post-type'] ) ) {
				$opts['post_types'] = $this->resolve_post_types( $assoc_args['post-type'] );
			}

			$state = $engine->scan_to_completion( $opts );

			if ( is_wp_error( $state ) ) {
				WP_CLI::error( $state->get_error_message() );
			}

			if ( ! is_array( $state ) ) {
				WP_CLI::error( 'The scan did not complete.' );
			}

			if ( ! empty( $state['quota_exceeded'] ) ) {
				WP_CLI::warning( 'The YouTube Data API quota was spent before every post was reached. The next scan continues from where this one stopped.' );
			}

			if ( ! empty( $state['fast'] ) ) {
				WP_CLI::warning( 'Fast check: a video that exists but is blocked from embedding is reported as working.' );
			}

			$links = isset( $state['broken_links'] ) ? $state['broken_links'] : array();
			if ( $found && ! empty( $state['found_links'] ) ) {
				$links = array_merge( $links, $state['found_links'] );
			}
			$rows = $this->scan_rows( $links );

			$fields = array( 'status', 'id', 'url', 'post', 'post_id' );
			if ( $found ) {
				$fields[] = 'broken';
			}

			$unchecked = isset( $state['unchecked'] ) ? (int) $state['unchecked'] : 0;

			if ( 'table' === $format && empty( $rows ) ) {
				WP_CLI::success( sprintf(
					'No %s found. Scanned %d posts, %d checked.',
					$found ? 'links' : 'broken links',
					(int) $state['total'],
					(int) $state['checked']
				) );
				$this->warn_unchecked( $unchecked );
				return;
			}

			WP_CLI\Utils\format_items( $format, $rows, $fields );

			if ( 'table' === $format ) {
				WP_CLI::log( '' );
				WP_CLI::log( sprintf(
					'Scanned %d posts. %d checked. %d broken.',
					(int) $state['total'],
					(int) $state['checked'],
					(int) $state['broken']
				) );
				$this->warn_unchecked( $unchecked );
			}

			$this->warn_truncated( $state, $found );

			if ( isset( $assoc_args['trash'] ) ) {
				$this->trash_broken( $state, $assoc_args );
			}
		}

		/**
		 * Prints what the rows left out, so a truncated listing is not
		 * mistaken for the whole picture. The totals printed above stay exact
		 * either way; it is only the listed rows that are capped.
		 */
		private function warn_truncated( $state, $found ) {
			$listed = isset( $state['broken_links'] ) ? count( $state['broken_links'] ) : 0;
			$broken = isset( $state['broken'] ) ? (int) $state['broken'] : 0;

			if ( $broken > $listed ) {
				WP_CLI::warning( sprintf(
					'Listed the first %1$d of %2$d broken links. The rest were not kept; raise the ytf_max_report_rows filter to keep more.',
					$listed,
					$broken
				) );
			}

			if ( $found && ! empty( $state['found_truncated'] ) ) {
				WP_CLI::warning( sprintf(
					'Working links were also capped at %d rows, so --found is not listing all of them.',
					count( isset( $state['found_links'] ) ? $state['found_links'] : array() )
				) );
			}
		}

		/**
		 * How many links got no result
		 */
		private function warn_unchecked( $unchecked ) {
			if ( $unchecked < 1 ) {
				return;
			}

			WP_CLI::warning( sprintf(
				_n(
					'%d link could not be checked and is not counted above. Run the scan again to retry it.',
					'%d links could not be checked and are not counted above. Run the scan again to retry them.',
					$unchecked,
					'youtube-forge'
				),
				$unchecked
			) );
		}

		/**
		 * Move every post that has a broken link to the trash. Destructive, so it
		 * confirms first (skipped with --yes); --dry-run reports without changing
		 * anything. Already trashed or missing posts are skipped.
		 */
		private function trash_broken( $state, $assoc_args ) {
			$ids   = array();
			$links = isset( $state['broken_links'] ) ? $state['broken_links'] : array();
			foreach ( YTF_Link::from_list( $links ) as $link ) {
				if ( $link->post_id ) {
					$ids[ $link->post_id ] = true;
				}
			}
			$ids = array_keys( $ids );

			if ( empty( $ids ) ) {
				WP_CLI::log( 'No posts with broken links to trash.' );
				return;
			}

			if ( isset( $assoc_args['dry-run'] ) ) {
				WP_CLI::log( sprintf( 'Dry run: %d posts have broken links and would be trashed.', count( $ids ) ) );
				return;
			}

			WP_CLI::confirm(
				sprintf(
					'DANGER: this will move %d posts to the trash. It affects the whole post, not just the broken link, and is not reversible from the CLI. Continue?',
					count( $ids )
				),
				$assoc_args
			);

			_prime_post_caches( $ids, false, false );

			$trashed = 0;
			$skipped = 0;
			foreach ( $ids as $pid ) {
				$status = get_post_status( $pid );
				if ( ! $status || 'trash' === $status ) {
					$skipped++;
					continue;
				}
				if ( wp_trash_post( $pid ) ) {
					$trashed++;
				} else {
					$skipped++;
				}
			}

			WP_CLI::success( sprintf( 'Trashed %d posts. %d skipped.', $trashed, $skipped ) );
		}

		/**
		 * Parse and validate a comma separated --post-type value against the
		 * registered public post types
		 */
		private function resolve_post_types( $value ) {
			$public = get_post_types( array( 'public' => true ) );
			$types  = array();

			foreach ( explode( ',', (string) $value ) as $part ) {
				$type = sanitize_key( trim( $part ) );
				if ( '' === $type ) {
					continue;
				}
				if ( ! isset( $public[ $type ] ) ) {
					WP_CLI::error( sprintf( 'Unknown or non public post type "%s". Available: %s.', $type, implode( ', ', $public ) ) );
				}
				$types[ $type ] = $type;
			}

			if ( empty( $types ) ) {
				WP_CLI::error( 'Pass at least one post type with --post-type.' );
			}

			return array_values( $types );
		}

		/**
		 * Map scan link entries to flat rows for output.
		 */
		private function scan_rows( $links ) {
			$rows = array();
			foreach ( YTF_Link::from_list( $links ) as $link ) {
				$rows[] = array(
					'status'  => $link->status,
					'id'      => $link->video_id,
					'url'     => $link->video_url,
					'post'    => $link->title,
					'post_id' => $link->post_id,
					'broken'  => $link->is_broken() ? 'yes' : 'no',
				);
			}
			return $rows;
		}

	}
}
