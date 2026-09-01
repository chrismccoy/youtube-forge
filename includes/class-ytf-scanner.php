<?php
/**
 * Scan engine: advances a scan one chunk per call, keeps history, renders reports.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Scanner' ) ) {

	/**
	 * Scan engine. Link extraction, API checking, and reporting
	 */
	class YTF_Scanner {

		/**
		 * Option holding the scan's progress.
		 */
		const STATE_OPTION = YTF_Scan_State_Store::STATE_OPTION;

		/**
		 * Option holding the scan's findings.
		 */
		const FIND_OPTION = YTF_Scan_State_Store::FIND_OPTION;

		/**
		 * Option name holding the list of finished scans.
		 */
		const HISTORY_OPTION = YTF_Scan_History::OPTION;

		/**
		 * How many finished scans to keep.
		 */
		const HISTORY_LIMIT = YTF_Scan_History::LIMIT;

		/**
		 * Option used as the mutex that stops two scans running at once.
		 */
		const LOCK_OPTION = YTF_Scan_Lock::OPTION;

		/**
		 * Option holding the post types to scan.
		 */
		const POST_TYPES_OPTION = YTF_Settings::POST_TYPES_OPTION;

		/**
		 * Option holding where a scan that stopped early can pick up from.
		 */
		const RESUME_OPTION = YTF_Scan_Resume::OPTION;

		/**
		 * How long a saved resume point stays usable.
		 */
		const RESUME_MAX_AGE = YTF_Scan_Resume::MAX_AGE;

		/**
		 * How many extra steps a scan will do re-checking links
		 */
		const RETRY_ROUNDS = 3;

		/**
		 * Cached filtered limit on stored rows
		 */
		private $max_rows = null;

		/**
		 * Cached filtered limit on log lines
		 */
		private $max_log = null;

		/**
		 * Cached filtered size of the rolling log window.
		 */
		private $log_window = null;

		/**
		 * The last post id already scanned
		 */
		private $keyset_after = 0;

		/**
		 * Post ids primed into the object cache by the last chunk, so a run that
		 * loops (WP-CLI) can release them again.
		 */
		private $last_post_ids = array();

		/**
		 * Chunks completed by this instance
		 */
		private $chunks_done = 0;

		/**
		 * The running scan's storage.
		 */
		private $state;

		/**
		 * The mutex that stops two scans running at once.
		 */
		private $lock;

		/**
		 * The list of finished scans.
		 */
		private $history;

		/**
		 * Where a scan that stopped early can pick up from.
		 */
		private $resume;

		/**
		 * Settings store the API key and post types are read from.
		 */
		private $settings;

		public function __construct( $settings = null ) {
			$this->settings = ( $settings instanceof YTF_Settings ) ? $settings : new YTF_Settings();
			$this->state    = new YTF_Scan_State_Store();
			$this->lock     = new YTF_Scan_Lock( array( $this, 'is_running' ) );
			$this->history  = new YTF_Scan_History();
			$this->resume   = new YTF_Scan_Resume( $this->settings );
		}

		/**
		 * Save a scan across its two options.
		 */
		private function save_state( $state, $findings_dirty ) {
			$this->state->save( $state, $findings_dirty );
		}

		/**
		 * Load the scan.
		 */
		private function load_state() {
			$state = $this->state->load();

			if ( false === $state ) {
				$this->delete_state();
				return null;
			}

			return $state;
		}

		/**
		 * Delete both scan options and release the lock.
		 */
		private function delete_state( $state = null ) {
			$this->state->delete();

			if ( is_array( $state ) && ! empty( $state['lock'] ) ) {
				$this->lock->adopt( $state['lock'] );
			}

			$this->lock->release();
		}

		/**
		 * Delete the saved scan history.
		 */
		public function clear_history() {
			$this->history->clear();
		}

		/**
		 * Return the saved scan history, newest first.
		 */
		public function get_history() {
			return $this->history->all();
		}

		/**
		 * Maximum broken and found rows kept per scan.
		 */
		private function max_report_rows() {
			if ( null === $this->max_rows ) {
				$rows = (int) apply_filters( 'ytf_max_report_rows', YTF_MAX_REPORT_ROWS );
				$this->max_rows = max( 1, $rows );
			}
			return $this->max_rows;
		}

		/**
		 * Maximum live log lines kept per scan.
		 */
		private function max_log_lines() {
			if ( null === $this->max_log ) {
				/** @var int $lines */
				$lines = (int) apply_filters( 'ytf_max_log_lines', YTF_MAX_LOG_LINES );
				$this->max_log = max( 1, $lines );
			}
			return $this->max_log;
		}

		/**
		 * How many log lines are held in the scan state at once.
		 */
		private function log_window() {
			if ( null === $this->log_window ) {
				$lines = (int) apply_filters( 'ytf_log_window', YTF_LOG_WINDOW );
				$this->log_window = max( 1, $lines );
			}
			return $this->log_window;
		}

		/**
		 * How many links may sit in the retry queue at once.
		 */
		private function max_retry_links() {
			$max = (int) apply_filters( 'ytf_max_retry_links', $this->max_report_rows() );
			return max( 0, $max );
		}

		/**
		 * Record one link as unchecked, respecting the stored row cap.
		 */
		private function file_unchecked( &$state, YTF_Link $link ) {
			$state['unchecked']++;

			if ( count( $state['unchecked_links'] ) < $this->max_report_rows() ) {
				$state['unchecked_links'][] = $link->to_array();
			} else {
				$state['truncated'] = true;
			}

			$status = '' !== $link->status ? $link->status : YTF_Api_Client::STATUS_ERROR;
			$this->log_line( $state, sprintf( '[%s] %s', $status, $link->video_url ) );
		}

		/**
		 * File everything still waiting in the retry queue as unchecked
		 */
		private function flush_retry_queue( &$state ) {
			if ( empty( $state['retry'] ) ) {
				return 0;
			}

			$filed = 0;
			foreach ( YTF_Link::from_list( $state['retry'] ) as $link ) {
				$this->file_unchecked( $state, $link );
				$filed++;
			}

			$state['retry'] = array();

			return $filed;
		}

		/**
		 * Seconds one scan step aims to take.
		 */
		private function chunk_budget() {
			return max( 1, (int) apply_filters( 'ytf_chunk_seconds', YTF_CHUNK_SECONDS ) );
		}

		/**
		 * The microtime() after which a step makes no further API calls.
		 */
		private function api_deadline( $started ) {
			$max = (int) ini_get( 'max_execution_time' );
			if ( $max <= 0 ) {
				return 0;
			}

			return $started + max( $this->chunk_budget(), $max - YTF_API_TIMEOUT );
		}

		/**
		 * Delete scan options left behind by a scan whose tab was closed mid run.
		 */
		public function purge_stale_state( $max_age = HOUR_IN_SECONDS ) {
			$state = $this->state->raw();
			if ( false === $state ) {
				return false;
			}

			if ( ! is_array( $state ) ) {
				$this->delete_state();
				return true;
			}

			$touched = isset( $state['touched'] ) ? (int) $state['touched'] : 0;
			if ( ( time() - $touched ) > (int) $max_age ) {
				$this->delete_state( $state );
				return true;
			}

			return false;
		}

		/**
		 * Whether a scan is running right now.
		 */
		public function is_running() {
			return $this->state->is_running();
		}

		/**
		 * Whether a scan can run: an API key is saved.
		 */
		public function is_ready() {
			return $this->settings->has_api_key();
		}

		/**
		 * Reset the scan state so the next advance() starts from the first post.
		 */
		public function start_scan( $opts = array() ) {
			$this->purge_stale_state();

			if ( ! $this->lock->claim() ) {
				return new WP_Error(
					'ytf_scan_running',
					__( 'A scan is already running. Wait for it to finish before starting another.', 'youtube-forge' )
				);
			}

			$state = $this->new_state();
			$state['lock'] = $this->lock->token();

			if ( isset( $opts['post_status'] ) && '' !== $opts['post_status'] ) {
				$state['post_status'] = (string) $opts['post_status'];
			}
			if ( ! empty( $opts['post_types'] ) && is_array( $opts['post_types'] ) ) {
				$state['post_types'] = array_values( $opts['post_types'] );
			}
			if ( isset( $opts['max_posts'] ) ) {
				$state['max_posts'] = max( 0, (int) $opts['max_posts'] );
			}
			if ( ! empty( $opts['keep_found'] ) ) {
				$state['keep_found'] = true;
			}
			$state['fast'] = (bool) apply_filters( 'ytf_fast_check', ! empty( $opts['fast'] ) );
			if ( $state['fast'] ) {
				$this->log_line( $state, __( 'Fast check: asking only whether each video exists. Videos that are blocked from embedding will read as working.', 'youtube-forge' ) );
			}
			if ( isset( $opts['origin'] ) && '' !== $opts['origin'] ) {
				$state['origin'] = sanitize_key( $opts['origin'] );
			}

			$this->log_line( $state, __( 'Scan started.', 'youtube-forge' ) );

			$resume = $this->resume->matching( $state );
			if ( $resume ) {
				$state['after_id']      = (int) $resume['after_id'];
				$state['resumed_total'] = (int) $resume['total'];
				$this->log_line( $state, sprintf(
					__( 'Continuing after the %d posts covered by the previous scan.', 'youtube-forge' ),
					(int) $resume['total']
				) );

				$this->resume->clear();
			}

			$this->save_state( $state, true );
			return $state;
		}

		/**
		 * Process one chunk of the scan and save it.
		 */
		public function advance( $ack_cursor = 0 ) {
			$state = $this->load_state();
			if ( ! is_array( $state ) ) {
				return null;
			}
			if ( 'running' !== $state['status'] ) {
				return $state;
			}

			$before = count( $state['broken_links'] ) + count( $state['unchecked_links'] )
				+ count( $state['found_links'] ) + count( $state['errors'] );
			$num    = $this->scan_chunk( $state );
			$after  = count( $state['broken_links'] ) + count( $state['unchecked_links'] )
				+ count( $state['found_links'] ) + count( $state['errors'] );
			$findings_dirty = ( $after !== $before );

			$out_of_quota = ! empty( $state['quota_exceeded'] );
			$hit_limit    = ! empty( $state['limit_reached'] );
			$exhausted    = ( 0 === $num );
			$finishing    = ( $exhausted || $out_of_quota || $hit_limit );

			$draining = (
				$finishing
				&& ! $out_of_quota
				&& ! empty( $state['retry'] )
				&& (int) $state['retry_rounds'] < self::RETRY_ROUNDS
			);

			if ( $draining ) {
				$state['retry_rounds'] = (int) $state['retry_rounds'] + 1;
				$this->log_line( $state, sprintf(
					__( 'Re-checking %1$d link(s) the API did not answer for (attempt %2$d of %3$d).', 'youtube-forge' ),
					count( $state['retry'] ),
					(int) $state['retry_rounds'],
					self::RETRY_ROUNDS
				) );
			}

			if ( $finishing && ! $draining ) {
				if ( $this->flush_retry_queue( $state ) > 0 ) {
					$findings_dirty = true;
				}

				if ( $out_of_quota ) {
					$this->log_line( $state, __( 'Stopped: the YouTube Data API quota is spent. Run the scan again once it resets.', 'youtube-forge' ), true );
					$this->resume->save( $state );
				}

				$state['status']   = 'done';
				$state['finished'] = time();
				$this->log_line( $state, sprintf(
					__( 'Scan complete. Scanned %1$d posts, %2$d checked, %3$d broken.', 'youtube-forge' ),
					$state['total'],
					$state['checked'],
					$state['broken']
				), true );

				$this->history->push( $state );

				$state['touched'] = time();
				$this->save_state( $state, $findings_dirty );

				$this->lock->adopt( isset( $state['lock'] ) ? $state['lock'] : '' );
				$this->lock->release();

				return $state;
			}

			$this->trim_log_to( $state, $ack_cursor );

			$state['touched'] = time();
			$this->save_state( $state, $findings_dirty );
			return $state;
		}

		/**
		 * Drop log lines already received.
		 */
		private function trim_log_to( &$state, $ack ) {
			$ack = (int) $ack;
			if ( $ack < 1 || empty( $state['log'] ) ) {
				return;
			}

			$offset = isset( $state['log_offset'] ) ? (int) $state['log_offset'] : 0;
			$drop   = min( $ack - $offset, count( $state['log'] ) );
			if ( $drop < 1 ) {
				return;
			}

			$state['log']        = array_values( array_slice( $state['log'], $drop ) );
			$state['log_offset'] = $offset + $drop;
		}

		/**
		 * Take the finished scan's report and clear the state behind it.
		 */
		public function take_report() {
			$state = $this->load_state();
			if ( ! is_array( $state ) || 'done' !== $state['status'] ) {
				return null;
			}

			$report = $this->report_data( $state );
			$this->delete_state( $state );
			return $report;
		}

		/**
		 * Return the current scan state
		 */
		public function get_state() {
			return $this->load_state();
		}

		/**
		 * One saved scan from the history, as report data.
		 */
		public function history_report_data( $index ) {
			$snapshot = $this->history->annotated( $index );
			if ( null === $snapshot ) {
				return null;
			}

			$post_ids = YTF_Scan_History::snapshot_post_ids( $snapshot );
			self::prime_posts( $post_ids );

			$report = $this->report_data( $snapshot, true, true );

			self::drop_posts( $post_ids );

			return $report;
		}

		/**
		 * Run a complete scan inline and return the final scan (with totals
		 * and the broken_links list)
		 */
		public function scan_to_completion( $opts = array() ) {
			$state = $this->start_scan( $opts );
			if ( is_wp_error( $state ) ) {
				return $state;
			}

			do {
				$state = $this->advance( PHP_INT_MAX );
				$this->free_chunk_memory();
			} while ( is_array( $state ) && 'running' === $state['status'] );

			$this->delete_state( is_array( $state ) ? $state : null );

			return $state;
		}

		/**
		 * Release what the last chunk primed into the object cache.
		 */
		private function free_chunk_memory() {
			global $wpdb;

			$this->chunks_done++;

			if ( ! wp_using_ext_object_cache() ) {
				foreach ( $this->last_post_ids as $post_id ) {
					wp_cache_delete( $post_id, 'posts' );
					wp_cache_delete( $post_id, 'post_meta' );
				}
			} elseif ( 0 === ( $this->chunks_done % 10 ) && function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}

			$this->last_post_ids = array();

			if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) {
				$wpdb->queries = array();
			}
		}

		/**
		 * Build the report from the scan, as data for the client to render.
		 */
		public function report_data( $state, $with_actions = true, $show_handled = false ) {
			$report = new YTF_Scan_Report( YTF_VERSION, $with_actions );
			$report->start( $state['started'] );

			$groups = array();
			$order  = array();
			foreach ( YTF_Link::from_list( $state['broken_links'] ) as $link ) {
				$pid = $link->post_id;
				if ( ! isset( $groups[ $pid ] ) ) {
					$groups[ $pid ] = array();
					$order[]        = $pid;
				}
				$groups[ $pid ][] = $link;
			}

			if ( ! empty( $order ) ) {
				self::prime_posts( $order );
			}

			foreach ( $order as $pid ) {
				$report->add_post_row( $groups[ $pid ] );
			}
			$unchecked = isset( $state['unchecked'] ) ? (int) $state['unchecked'] : 0;
			$report->finish( $state['total'], $state['checked'], $state['broken'], $state['finished'], $unchecked );
			$trashed = isset( $state['trashed_count'] ) ? (int) $state['trashed_count'] : 0;
			$deleted = isset( $state['deleted_count'] ) ? (int) $state['deleted_count'] : 0;
			if ( $show_handled || $trashed > 0 || $deleted > 0 ) {
				$report->handled( $trashed, $deleted );
			}
			if ( $show_handled ) {
				$report->resolved( ! empty( $state['resolved'] ) );
			} elseif ( ! empty( $state['resolved'] ) ) {
				$report->resolved( true );
			}
			$resumed = isset( $state['resumed_total'] ) ? (int) $state['resumed_total'] : 0;
			if ( $resumed > 0 ) {
				$report->note( sprintf(
					__( 'This scan continued an earlier one that stopped on a spent API quota. The earlier scan covered %1$d posts; the count below is the %2$d this one covered.', 'youtube-forge' ),
					$resumed,
					(int) $state['total']
				) );
			}
			if ( ! empty( $state['quota_exceeded'] ) ) {
				$report->note( __( 'This scan stopped early: the YouTube Data API quota was spent before every post was reached. The next scan continues from where it stopped.', 'youtube-forge' ) );
			}
			if ( ! empty( $state['fast'] ) ) {
				$report->note( __( 'This scan used the fast check, which only asks whether each video still exists. Videos that are private or removed are still found, but one that exists and is blocked from embedding is reported as working.', 'youtube-forge' ) );
			}
			$shown = count( $state['broken_links'] );
			if ( (int) $state['broken'] > $shown ) {
				$report->note( sprintf(
					__( 'Showing the first %1$d of %2$d broken links. Entries beyond the cap are not listed; the totals above are exact.', 'youtube-forge' ),
					$shown,
					(int) $state['broken']
				) );
			}
			return $report->render( $this->errors_text( $state['errors'] ) );
		}

		/**
		 * The error block for a report
		 */
		private function errors_text( $errors ) {
			$errors = (array) $errors;
			$max    = max( 1, (int) apply_filters( 'ytf_max_report_errors', YTF_MAX_REPORT_ERRORS ) );
			$shown  = array_slice( $errors, 0, $max );
			$text   = implode( "\n", $shown );

			$hidden = count( $errors ) - count( $shown );
			if ( $hidden > 0 ) {
				$text .= "\n" . sprintf(
					_n( '... and %d more error.', '... and %d more errors.', $hidden, 'youtube-forge' ),
					$hidden
				);
			}

			return $text;
		}

		/**
		 * Prime the caches for a set of post ids before a report is rendered.
		 */
		public static function prime_posts( $ids, $needs_terms = null ) {
			$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
			$ids = array_filter( $ids );
			if ( empty( $ids ) ) {
				return;
			}

			if ( null === $needs_terms ) {
				$needs_terms = ( false !== strpos( (string) get_option( 'permalink_structure' ), '%category%' ) );
			}

			foreach ( array_chunk( $ids, 100 ) as $batch ) {
				_prime_post_caches( $batch, $needs_terms, false );
			}
		}

		/**
		 * Drop a set of primed posts from the object cache.
		 */
		public static function drop_posts( $ids ) {
			if ( wp_using_ext_object_cache() ) {
				return;
			}

			foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $post_id ) {
				if ( ! $post_id ) {
					continue;
				}
				wp_cache_delete( $post_id, 'posts' );
				wp_cache_delete( $post_id, 'post_meta' );
			}
		}

		/**
		 * Post types to scan, from the settings store.
		 */
		public function scan_post_types() {
			return $this->settings->post_types();
		}

		/**
		 * Build a fresh scan array.
		 */
		private function new_state() {
			return array(
				'status'          => 'running',
				'after_id'        => 0,
				'total'           => 0,
				'checked'         => 0,
				'broken'          => 0,
				'unchecked'       => 0,
				'broken_links'    => array(),
				'unchecked_links' => array(),
				'found_links'     => array(),
				'errors'          => array(),
				'log'             => array(),
				'log_offset'      => 0,
				'retry'           => array(),
				'retry_rounds'    => 0,
				'log_truncated'   => false,
				'truncated'       => false,
				'found_truncated' => false,
				'quota_exceeded'  => false,
				'limit_reached'   => false,
				'resumed_total'   => 0,
				'chunksize'       => YTF_BATCH_POSTS,
				'requested'       => YTF_BATCH_POSTS,
				'post_status'     => 'publish',
				'post_types'      => array(),
				'max_posts'       => 0,
				'keep_found'      => false,
				'fast'            => false,
				'origin'          => '',
				'lock'            => '',
				'started'         => time(),
				'touched'         => time(),
				'finished'        => 0,
			);
		}

		/**
		 * Append one line to the scan log, limited to the log line limit.
		 */
		private function log_line( &$state, $line, $force = false ) {
			if ( ! isset( $state['log_offset'] ) ) {
				$state['log_offset'] = 0;
			}

			$emitted = (int) $state['log_offset'] + count( $state['log'] );

			if ( ! $force && $emitted >= $this->max_log_lines() ) {
				if ( ! empty( $state['log_truncated'] ) ) {
					return;
				}
				$line = sprintf(
					__( 'Log truncated at %d lines.', 'youtube-forge' ),
					$this->max_log_lines()
				);
				$state['log_truncated'] = true;
			}

			$state['log'][] = $line;

			$over = count( $state['log'] ) - $this->log_window();
			if ( $over > 0 ) {
				$state['log']        = array_values( array_slice( $state['log'], $over ) );
				$state['log_offset'] = (int) $state['log_offset'] + $over;
			}
		}

		/**
		 * Read one chunk of posts, check their videos, and add the results into the scan.
		 */
		private function scan_chunk( &$state ) {
			$started = microtime( true );

			$plan  = $this->plan_chunk( $state );
			$posts = $this->query_chunk_posts( $state, $plan );
			$num   = count( $posts );

			$log = new YTF_Error_Log();

			$map = $this->collect_links( $state, $posts, $log );
			$map = $this->apply_verdicts( $state, $map, $log, $started );

			$stalled = $this->tally_links( $state, $map );

			$this->record_errors( $state, $log );
			$this->advance_cursor( $state, $num );

			$this->fit_chunk_to_time( $state, $plan['size'], microtime( true ) - $started, $stalled );

			$this->log_chunk_progress( $state, $num );

			return $num;
		}

		/**
		 * How many posts this step should plan for
		 */
		private function plan_chunk( &$state ) {
			$size  = $state['chunksize'];
			$drain = false;

			$limit = isset( $state['max_posts'] ) ? (int) $state['max_posts'] : 0;
			if ( $limit > 0 ) {
				$remaining = $limit - $state['total'];
				if ( $remaining <= 0 ) {
					$state['limit_reached'] = true;
					$drain = true;
				} else {
					$size = min( $size, $remaining );
				}
			}

			return array(
				'size'  => $size,
				'drain' => $drain,
			);
		}

		/**
		 * Read the next batch of posts
		 */
		private function query_chunk_posts( &$state, $plan ) {
			$post_types = ( ! empty( $state['post_types'] ) && is_array( $state['post_types'] ) )
				? $state['post_types']
				: $this->scan_post_types();

			$query_args = array(
				'numberposts'            => $plan['size'],
				'post_type'              => $post_types,
				'post_status'            => isset( $state['post_status'] ) ? $state['post_status'] : 'publish',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			);
			$query_args = apply_filters( 'ytf_scan_posts', $query_args );

			$query_args['ytf_keyset'] = true;

			$asked = isset( $query_args['numberposts'] ) ? (int) $query_args['numberposts'] : $plan['size'];
			$state['requested'] = $plan['drain'] ? 0 : $asked;

			$posts = array();
			if ( ! $plan['drain'] ) {
				$this->keyset_after = (int) $state['after_id'];
				add_filter( 'posts_where', array( $this, 'keyset_where' ), 10, 2 );
				$posts = get_posts( $query_args );
				remove_filter( 'posts_where', array( $this, 'keyset_where' ), 10 );
			}

			$this->last_post_ids = array();
			foreach ( $posts as $post ) {
				$this->last_post_ids[] = (int) $post->ID;
			}

			return $posts;
		}

		/**
		 * The links this step has to check
		 */
		private function collect_links( &$state, $posts, $log ) {
			$extractor = new YTF_Link_Extractor( $log );
			$map       = $extractor->extract_from_posts( $posts );

			$waiting = array();
			foreach ( YTF_Link::from_list( $state['retry'] ) as $key => $entry ) {
				$waiting[ $key ] = $entry->without_verdict();
			}
			$state['retry'] = array();

			if ( ! empty( $waiting ) ) {
				$map = $waiting + $map;
			}

			return $map;
		}

		/**
		 * Check the API about every video id in the map
		 */
		private function apply_verdicts( &$state, $map, $log, $started ) {
			$items = array();
			foreach ( $map as $entry ) {
				$items[ $entry->video_id ] = true;
			}

			if ( empty( $items ) ) {
				return $map;
			}

			$client   = new YTF_Api_Client( $log, $this->settings, ! empty( $state['fast'] ) );
			$deadline = $this->api_deadline( $started );
			$verdict  = $client->check_ids( array_keys( $items ), $deadline );

			if ( $client->quota_exceeded() ) {
				$state['quota_exceeded'] = true;
			}

			foreach ( $map as $key => $entry ) {
				$vid = $entry->video_id;
				if ( isset( $verdict[ $vid ] ) ) {
					$map[ $key ] = $entry->with_verdict( $verdict[ $vid ]['broken'], $verdict[ $vid ]['status'] );
				}
			}

			return $map;
		}

		/**
		 * Count every link result into the scan and log it.
		 */
		private function tally_links( &$state, $map ) {
			$stalled = false;

			foreach ( $map as $key => $link ) {
				if ( ! $link->has_verdict() ) {
					continue;
				}

				if ( $link->is_broken() ) {
					$state['checked']++;
					$state['broken']++;
					$this->log_line( $state, sprintf( '[%s] %s', $link->status, $link->video_url ) );
					if ( count( $state['broken_links'] ) < $this->max_report_rows() ) {
						$state['broken_links'][] = $link->to_array();
					} else {
						$state['truncated'] = true;
					}
				} elseif ( $link->is_unanswered() ) {
					$stalled = true;

					if ( count( $state['retry'] ) < $this->max_retry_links() ) {
						$state['retry'][ $key ] = $link->to_array();
						$this->log_line( $state, sprintf( '[RETRY] %s', $link->video_url ) );
					} else {
						$this->file_unchecked( $state, $link );
					}
				} else {
					$state['checked']++;
					$this->log_line( $state, sprintf( '[FOUND] %s', $link->video_url ) );
					if ( ! empty( $state['keep_found'] ) ) {
						if ( count( $state['found_links'] ) < $this->max_report_rows() ) {
							$state['found_links'][] = $link->to_array();
						} else {
							$state['found_truncated'] = true;
						}
					}
				}
			}

			return $stalled;
		}

		/**
		 * Add the errors in the live log
		 */
		private function record_errors( &$state, $log ) {
			$new_errors = $log->all();

			foreach ( $new_errors as $err ) {
				$this->log_line( $state, wp_strip_all_tags( $err ) );
			}

			$room = $this->max_report_rows() - count( $state['errors'] );
			if ( $room > 0 ) {
				$state['errors'] = array_merge( $state['errors'], array_slice( $new_errors, 0, $room ) );
			}

			if ( count( $new_errors ) > max( $room, 0 ) ) {
				$state['truncated'] = true;
			}
		}

		/**
		 * Move the keyset cursor past this batch and add it to the running total.
		 */
		private function advance_cursor( &$state, $num ) {
			if ( $num > 0 ) {
				$state['after_id'] = max( $this->last_post_ids );
			}

			$state['total'] += $num;

			$limit = isset( $state['max_posts'] ) ? (int) $state['max_posts'] : 0;
			if ( $limit > 0 && $state['total'] >= $limit ) {
				$state['limit_reached'] = true;
			}
		}

		/**
		 * The "Scanned N posts" line
		 */
		private function log_chunk_progress( &$state, $num ) {
			if ( $num < 1 ) {
				return;
			}

			if ( $state['total'] === $num ) {
				$line = sprintf( _n( 'Scanned %d post.', 'Scanned %d posts.', $num, 'youtube-forge' ), $num );
			} else {
				$line = sprintf( _n( 'Scanned %d more post.', 'Scanned %d more posts.', $num, 'youtube-forge' ), $num );
			}

			$this->log_line( $state, $line );
		}

		/**
		 * Move the posts per step
		 */
		private function fit_chunk_to_time( &$state, $size, $elapsed, $stalled = false ) {
			$budget = $this->chunk_budget();
			$before = (int) $state['chunksize'];

			if ( ( $stalled || $elapsed > $budget ) && $size > 1 ) {
				$state['chunksize'] = max( 1, (int) floor( $size / 2 ) );
			} elseif ( ! $stalled && $elapsed < ( $budget / 3 ) && $before < YTF_BATCH_POSTS ) {
				$state['chunksize'] = min( YTF_BATCH_POSTS, $before * 2 );
			}

			if ( $before !== (int) $state['chunksize'] ) {
				$this->log_line( $state, sprintf(
					__( 'Step took %1$.1fs. Posts per step %2$d to %3$d.', 'youtube-forge' ),
					$elapsed,
					$before,
					(int) $state['chunksize']
				) );
			}
		}

		/**
		 * posts_where filter for pagination.
		 */
		public function keyset_where( $where, $query = null ) {
			if ( ! $query instanceof WP_Query || empty( $query->query_vars['ytf_keyset'] ) ) {
				return $where;
			}

			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $this->keyset_after );
		}

	}
}
