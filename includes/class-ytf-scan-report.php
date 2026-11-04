<?php
/**
 * Builds the broken link report as structured data for the client to render.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Scan_Report' ) ) {

	/**
	 * Builds the scan report.
	 */
	class YTF_Scan_Report {

		/**
		 * Plugin version
		 */
		private $version;

		/**
		 * Whether to include the Actions column (View, Edit, Trash). Off for the
		 * historical Logs view
		 */
		private $with_actions = true;

		/**
		 * Whether this user can edit each post type, keyed by slug.
		 */
		private $can_edit = array();

		/**
		 * The report as it is assembled.
		 */
		private $report;

		public function __construct( $version, $with_actions = true ) {
			$this->version      = $version;
			$this->with_actions = (bool) $with_actions;

			$this->report = array(
				'startedText'   => '',
				'endedText'     => '',
				'summaryText'   => '',
				'uncheckedText' => '',
				'withActions'   => $this->with_actions,
				'trashAllText'  => __( 'Trash all broken posts', 'youtube-forge' ),
				'columns'       => array(
					'status'   => __( 'Status', 'youtube-forge' ),
					'url'      => __( 'URL', 'youtube-forge' ),
					'location' => __( 'Location', 'youtube-forge' ),
					'title'    => __( 'Title', 'youtube-forge' ),
					'type'     => __( 'Type', 'youtube-forge' ),
					'actions'  => __( 'Actions', 'youtube-forge' ),
				),
				'rows'          => array(),
				'handled'       => null,
				'resolved'      => null,
				'notes'         => array(),
				'errorsHeading' => __( 'Errors', 'youtube-forge' ),
				'errorsText'    => '',
			);
		}

		/**
		 * Whether the current user can edit posts of a given type.
		 */
		private function can_edit_type( $slug ) {
			if ( '' === $slug ) {
				return false;
			}

			if ( ! isset( $this->can_edit[ $slug ] ) ) {
				$object = get_post_type_object( $slug );

				$this->can_edit[ $slug ] = ( $object && isset( $object->cap->edit_posts ) )
					? current_user_can( $object->cap->edit_posts )
					: false;
			}

			return $this->can_edit[ $slug ];
		}

		/**
		 * Format a unix timestamp in the site timezone, or an empty string.
		 */
		private function stamp( $ts ) {
			return $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : '';
		}

		/**
		 * Record the scan start time.
		 */
		public function start( $started_ts ) {
			$this->report['startedText'] = sprintf(
				__( 'Scan started: %s', 'youtube-forge' ),
				$this->stamp( $started_ts )
			);
		}

		/**
		 * Add one row for a post and all of its broken links.
		 */
		public function add_post_row( $links ) {
			if ( empty( $links ) ) {
				return;
			}

			$links = array_values( YTF_Link::from_list( $links ) );
			$first = $links[0];
			$count = count( $links );

			$items = array();
			foreach ( $links as $link ) {
				$items[] = array(
					'status'   => $link->status,
					'url'      => $link->video_url,
					'location' => $link->location,
				);
			}

			$type_slug = $first->post_type_slug();

			$row = array(
				'postID'    => $first->post_id,
				'count'     => $count,
				'links'     => $items,
				'title'     => $first->title,
				'multiText' => ( $count > 1 )
					? sprintf( _n( '%d broken link', '%d broken links', $count, 'youtube-forge' ), $count )
					: '',
				'typeLabel' => $this->type_label( $type_slug ),
				'state'     => '',
				'viewUrl'   => '',
				'editUrl'   => '',
			);

			if ( $this->with_actions ) {
				$post_state = $first->post_state;

				if ( 'trashed' === $post_state || 'deleted' === $post_state ) {
					$row['state'] = $post_state;
				} else {
					$row['viewUrl'] = $first->permalink();
					$row['editUrl'] = $this->can_edit_type( $type_slug )
						? (string) get_edit_post_link( $first->post_id, 'raw' )
						: '';
				}
			}

			$this->report['rows'][] = $row;
		}

		/**
		 * Human label for a post type slug (Post, Page, and so on)
		 */
		private function type_label( $slug ) {
			if ( '' === $slug ) {
				return '';
			}

			$object = get_post_type_object( $slug );
			if ( $object && isset( $object->labels->singular_name ) && '' !== $object->labels->singular_name ) {
				return $object->labels->singular_name;
			}

			return ucfirst( $slug );
		}

		/**
		 * Record the end time and the totals.
		 */
		public function finish( $total_posts, $checked, $broken, $finished_ts, $unchecked = 0 ) {
			$this->report['endedText'] = sprintf(
				__( 'Scan ended: %s', 'youtube-forge' ),
				$this->stamp( $finished_ts )
			);

			$posts_txt   = sprintf( _n( '%d post', '%d posts', $total_posts, 'youtube-forge' ), $total_posts );
			$checked_txt = sprintf( _n( '%d link checked', '%d links checked', $checked, 'youtube-forge' ), $checked );
			$broken_txt  = sprintf( _n( '%d link broken', '%d links broken', $broken, 'youtube-forge' ), $broken );

			$this->report['summaryText'] = sprintf(
				__( 'Scanned %1$s. %2$s. %3$s.', 'youtube-forge' ),
				$posts_txt,
				$checked_txt,
				$broken_txt
			);

			$unchecked = (int) $unchecked;
			if ( $unchecked > 0 ) {
				$this->report['uncheckedText'] = sprintf(
					_n(
						'%d link could not be checked, so it is not included above. Run the scan again to retry it.',
						'%d links could not be checked, so they are not included above. Run the scan again to retry them.',
						$unchecked,
						'youtube-forge'
					),
					$unchecked
				);
			}
		}

		/**
		 * Record how many of the broken links have been trashed or deleted since the scan.
		 */
		public function handled( $trashed, $deleted ) {
			$this->report['handled'] = array(
				'template'     => __( 'Of the links found: %1$s, %2$s.', 'youtube-forge' ),
				'trashed'      => (int) $trashed,
				'deleted'      => (int) $deleted,
				'trashedLabel' => __( 'trashed', 'youtube-forge' ),
				'deletedLabel' => __( 'deleted', 'youtube-forge' ),
			);
		}

		/**
		 * Record the Resolved badge, shown when every broken link found has since
		 * been trashed or deleted.
		 */
		public function resolved( $visible = true ) {
			$this->report['resolved'] = array(
				'visible' => (bool) $visible,
				'text'    => __( 'Resolved. Every broken link found here has been trashed or deleted.', 'youtube-forge' ),
			);
		}

		/**
		 * Add a notice line to the report.
		 */
		public function note( $text ) {
			$this->report['notes'][] = (string) $text;
		}

		/**
		 * Return the finished report.
		 */
		public function render( $errors_text ) {
			$this->report['errorsText'] = trim( (string) $errors_text );
			return $this->report;
		}

	}
}
