<?php
/**
 * One YouTube link found in a post, and the result for it.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Link' ) ) {

	/**
	 * One YouTube link found in a post: where it was found, and what the API
	 * result responsd with.
	 */
	final class YTF_Link {

		public function __construct(
			public readonly string $video_id,
			public readonly string $video_url,
			public readonly string $location,
			public readonly string $title,
			public readonly string $post_type,
			public readonly int $post_id,
			public readonly ?bool $broken = null,
			public readonly string $status = '',
			public readonly string $post_state = '',
			public readonly string $post_url = ''
		) {
		}

		/**
		 * Build a link from a stored entry.
		 */
		public static function from_array( $data ) {
			$data = is_array( $data ) ? $data : array();

			$has_verdict = array_key_exists( 'broken', $data );

			return new self(
				isset( $data['videoID'] ) ? (string) $data['videoID'] : '',
				isset( $data['videoUrl'] ) ? (string) $data['videoUrl'] : '',
				isset( $data['location'] ) ? (string) $data['location'] : '',
				isset( $data['title'] ) ? (string) $data['title'] : '',
				isset( $data['postType'] ) ? (string) $data['postType'] : '',
				isset( $data['postID'] ) ? (int) $data['postID'] : 0,
				$has_verdict ? (bool) $data['broken'] : null,
				isset( $data['status'] ) ? trim( (string) $data['status'] ) : '',
				isset( $data['post_state'] ) ? (string) $data['post_state'] : '',
				isset( $data['postUrl'] ) ? (string) $data['postUrl'] : ''
			);
		}

		/**
		 * Build a list of links from a list of stored entries.
		 */
		public static function from_list( $rows ) {
			$links = array();
			foreach ( (array) $rows as $key => $row ) {
				$links[ $key ] = self::from_array( $row );
			}
			return $links;
		}

		/**
		 * The stored result.
		 */
		public function to_array() {
			$data = array(
				'videoID'  => $this->video_id,
				'videoUrl' => $this->video_url,
				'location' => $this->location,
				'title'    => $this->title,
				'postType' => $this->post_type,
				'postID'   => $this->post_id,
			);

			if ( $this->has_verdict() ) {
				$data['broken'] = $this->broken ? 1 : 0;
				$data['status'] = $this->status;
			}

			if ( '' !== $this->post_state ) {
				$data['post_state'] = $this->post_state;
			}

			if ( '' !== $this->post_url ) {
				$data['postUrl'] = $this->post_url;
			}

			return $data;
		}

		/**
		 * Whether the API has a result for this link.
		 */
		public function has_verdict() {
			return null !== $this->broken;
		}

		/**
		 * Whether the link is broken.
		 */
		public function is_broken() {
			return true === $this->broken;
		}

		/**
		 * Whether the result is one of the two: the call failed, or
		 * the ran out of time before reaching this link.
		 */
		public function is_unanswered() {
			return YTF_Api_Client::STATUS_ERROR === $this->status
				|| YTF_Api_Client::STATUS_TIMEOUT === $this->status;
		}

		/**
		 * The same link carrying a result
		 */
		public function with_verdict( $broken, $status ) {
			return new self(
				$this->video_id,
				$this->video_url,
				$this->location,
				$this->title,
				$this->post_type,
				$this->post_id,
				(bool) $broken,
				trim( (string) $status ),
				$this->post_state,
				$this->post_url
			);
		}

		/**
		 * The same link with its result cleared, ready to be checked again.
		 */
		public function without_verdict() {
			return new self(
				$this->video_id,
				$this->video_url,
				$this->location,
				$this->title,
				$this->post_type,
				$this->post_id,
				null,
				'',
				$this->post_state,
				$this->post_url
			);
		}

		/**
		 * The post type slug, falling back to the post itself for rows saved
		 * before the slug was stored.
		 */
		public function post_type_slug() {
			if ( '' !== $this->post_type ) {
				return $this->post_type;
			}

			return $this->post_id ? (string) get_post_type( $this->post_id ) : '';
		}

		/**
		 * The public URL of the post this link was found in.
		 */
		public function permalink() {
			if ( '' !== $this->post_url ) {
				return $this->post_url;
			}

			if ( ! $this->post_id ) {
				return '';
			}

			$url = get_permalink( $this->post_id );
			return $url ? $url : '';
		}

	}
}
