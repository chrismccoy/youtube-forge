<?php
/**
 * The list of finished scans shown on the Logs page.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Scan_History' ) ) {

	/**
	 * Stores finished scans, newest first, and tags an old snapshot's links
	 */
	class YTF_Scan_History {

		/**
		 * Option name holding the list of finished scans.
		 */
		const OPTION = 'ytf_scan_history';

		/**
		 * How many finished scans to keep.
		 */
		const LIMIT = 10;

		/**
		 * Cached filtered limit on rows kept per entry.
		 */
		private $max_rows = null;

		/**
		 * Maximum rows kept per entry in the saved scan history.
		 */
		private function max_rows() {
			if ( null === $this->max_rows ) {
				/** @var int $rows */
				$rows = (int) apply_filters( 'ytf_max_history_rows', YTF_MAX_HISTORY_ROWS );
				$this->max_rows = max( 1, $rows );
			}
			return $this->max_rows;
		}

		/**
		 * Return the saved scan history, newest first.
		 */
		public function all() {
			$history = get_option( self::OPTION );
			return is_array( $history ) ? $history : array();
		}

		/**
		 * Delete the saved scan history.
		 */
		public function clear() {
			delete_option( self::OPTION );
		}

		/**
		 * Store a finished scan in the history, limited to LIMIT entries.
		 */
		public function push( $state ) {
			$cap = $this->max_rows();

			$all_broken    = $state['broken_links'];
			$all_unchecked = isset( $state['unchecked_links'] ) ? $state['unchecked_links'] : array();
			$all_errors    = $state['errors'];

			$broken    = array_slice( $all_broken, 0, $cap );
			$unchecked = array_slice( $all_unchecked, 0, $cap );
			$errors    = array_slice( $all_errors, 0, $cap );

			$trimmed = count( $broken ) < count( $all_broken )
				|| count( $unchecked ) < count( $all_unchecked )
				|| count( $errors ) < count( $all_errors );

			$snapshot = array(
				'origin'          => isset( $state['origin'] ) ? $state['origin'] : '',
				'started'         => $state['started'],
				'finished'        => $state['finished'],
				'total'           => $state['total'],
				'checked'         => $state['checked'],
				'broken'          => $state['broken'],
				'unchecked'       => isset( $state['unchecked'] ) ? (int) $state['unchecked'] : 0,
				'broken_links'    => $broken,
				'unchecked_links' => $unchecked,
				'errors'          => $errors,
				'truncated'       => ! empty( $state['truncated'] ) || $trimmed,
				'quota_exceeded'  => ! empty( $state['quota_exceeded'] ),
				'fast'            => ! empty( $state['fast'] ),
				'resumed_total'   => isset( $state['resumed_total'] ) ? (int) $state['resumed_total'] : 0,
			);

			$history = $this->all();
			array_unshift( $history, $snapshot );
			$history = array_slice( $history, 0, self::LIMIT );
			update_option( self::OPTION, $history, false );
		}

		/**
		 * One saved scan, with each broken link tagged by the current state of
		 * its post.
		 */
		public function annotated( $index ) {
			$history = $this->all();
			$index   = (int) $index;

			if ( ! isset( $history[ $index ] ) || ! is_array( $history[ $index ] ) ) {
				return null;
			}

			$snapshot = $history[ $index ];
			unset( $history );

			return $this->annotate( $snapshot );
		}

		/**
		 * The post ids referenced by a history snapshot.
		 */
		public static function snapshot_post_ids( $snapshot ) {
			if ( empty( $snapshot['broken_links'] ) || ! is_array( $snapshot['broken_links'] ) ) {
				return array();
			}

			$ids = array();
			foreach ( $snapshot['broken_links'] as $link ) {
				if ( ! empty( $link['postID'] ) ) {
					$ids[ (int) $link['postID'] ] = true;
				}
			}

			return array_keys( $ids );
		}

		/**
		 * Return a history snapshot with each broken link tagged by the current
		 * state of its post: 'trashed' when in the trash, 'deleted' when deleted.
		 */
		private function annotate( $snapshot ) {
			if ( empty( $snapshot['broken_links'] ) || ! is_array( $snapshot['broken_links'] ) ) {
				return $snapshot;
			}

			$statuses = array();
			foreach ( self::snapshot_post_ids( $snapshot ) as $post_id ) {
				$statuses[ $post_id ] = get_post_status( $post_id );
			}

			$active  = 0;
			$trashed = 0;
			$deleted = 0;
			foreach ( $snapshot['broken_links'] as $key => $link ) {
				$post_id = isset( $link['postID'] ) ? (int) $link['postID'] : 0;
				$status  = ( $post_id && isset( $statuses[ $post_id ] ) ) ? $statuses[ $post_id ] : false;

				if ( ! $status ) {
					$snapshot['broken_links'][ $key ]['post_state'] = 'deleted';
					$deleted++;
				} elseif ( 'trash' === $status ) {
					$snapshot['broken_links'][ $key ]['post_state'] = 'trashed';
					$trashed++;
				} else {
					$active++;
				}
			}

			$snapshot['trashed_count'] = $trashed;
			$snapshot['deleted_count'] = $deleted;
			$snapshot['resolved']      = ( 0 === $active );

			return $snapshot;
		}

	}
}
