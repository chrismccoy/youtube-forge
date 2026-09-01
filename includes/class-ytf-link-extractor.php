<?php
/**
 * Finds YouTube links inside post content and post meta.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Link_Extractor' ) ) {

	/**
	 * Finds YouTube links inside post content and every post meta value
	 */
	class YTF_Link_Extractor {

		/**
		 * Link pattern.
		 */
		const PATTERN = '%(?:https?://)?(?:www\.)?(?:youtube\.com/(?:watch\?v=|embed/|shorts/|live/|v/)|youtu\.be/)(?!videoseries)(?<id>[a-zA-Z0-9_-]{11})%i';

		/**
		 * Error logger.
		 */
		private $logger;

		public function __construct( $logger ) {
			$this->logger = $logger;
		}

		/**
		 * The patterns that find YouTube links
		 */
		public function patterns() {
			$patterns = (array) apply_filters( 'ytf_link_patterns', array( self::PATTERN ) );
			return array_values( array_filter( $patterns ) );
		}

		/**
		 * Extract links from a batch of posts.
		 */
		public function extract_from_posts( $posts ) {
			$map = array();

			if ( empty( $posts ) ) {
				return $map;
			}

			$allowed_meta = array_filter( array_map( 'strval', (array) apply_filters( 'ytf_scan_meta_keys', array() ) ) );
			$allowed_meta = empty( $allowed_meta ) ? array() : array_fill_keys( $allowed_meta, true );
			$needles      = $this->url_needles();
			$patterns     = $this->patterns();

			foreach ( $posts as $post ) {
				$this->scan_text( $post, $post->post_content, 'post content', $patterns, $map );

				$meta = get_post_meta( $post->ID );
				if ( ! is_array( $meta ) ) {
					continue;
				}

				if ( ! empty( $allowed_meta ) ) {
					$meta = array_intersect_key( $meta, $allowed_meta );
				}

				foreach ( $meta as $meta_key => $values ) {
					$text = $this->value_to_text( $values );
					if ( '' === trim( $text ) ) {
						continue;
					}
					if ( ! $this->has_needle( $text, $needles ) ) {
						continue;
					}
					$this->scan_text( $post, $text, 'meta: ' . $meta_key, $patterns, $map );
				}
			}

			return $map;
		}

		/**
		 * Substrings that mark a value as possibly holding a YouTube link.
		 */
		private function url_needles() {
			$needles = apply_filters( 'ytf_scan_meta_needles', array( 'http', 'youtu' ) );
			return array_values( array_filter( array_map( 'strtolower', (array) $needles ) ) );
		}

		/**
		 * Whether a block of text contains any of the given needles.
		 */
		private function has_needle( $text, $needles ) {
			if ( empty( $needles ) ) {
				return true;
			}
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $text, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Flatten a meta value (which may be an array of values, or a serialized
		 * array once unserialized) into one searchable string.
		 */
		private function value_to_text( $value ) {
			if ( is_string( $value ) ) {
				return $value;
			}
			if ( is_scalar( $value ) ) {
				return (string) $value;
			}
			if ( is_array( $value ) ) {
				$out = '';
				foreach ( $value as $item ) {
					$out .= ' ' . $this->value_to_text( $item );
				}
				return $out;
			}
			return '';
		}

		/**
		 * Run every pattern over one block of text and add matches.
		 */
		private function scan_text( $post, $text, $location, $patterns, &$map ) {
			if ( '' === (string) $text ) {
				return;
			}
			foreach ( $patterns as $pattern ) {
				$this->collect( $post, $text, $location, $pattern, $map );
			}
		}

		/**
		 * Run one pattern over a block of text and add matches to the map.
		 */
		private function collect( $post, $text, $location, $pattern, &$map ) {
			if ( false === preg_match_all( $pattern, $text, $matches, PREG_PATTERN_ORDER ) ) {
				$this->logger->add_error( sprintf( __( '%s preg_match_all ERROR on pattern %s.', 'youtube-forge' ), __FUNCTION__, $pattern ) );
				return;
			}

			if ( empty( $matches[0] ) ) {
				return;
			}

			$ids = isset( $matches['id'] ) ? $matches['id'] : array();

			foreach ( $matches[0] as $key => $video_url ) {
				$video_id = isset( $ids[ $key ] ) ? trim( $ids[ $key ] ) : '';
				$this->add_entry( $video_url, $video_id, $post, $location, $map );
			}
		}

		/**
		 * Build an entry and add it to the map
		 */
		private function add_entry( $video_url, $video_id, $post, $location, &$map ) {
			if ( '' === $video_id ) {
				$this->logger->add_error( sprintf( __( '%s Error: No ID for : %s in post %s', 'youtube-forge' ), __FUNCTION__, $video_url, (int) $post->ID ) );
				return;
			}

			$key = (int) $post->ID . ':' . $location . ':' . $video_id;
			if ( isset( $map[ $key ] ) ) {
				return;
			}

			$map[ $key ] = new YTF_Link(
				(string) $video_id,
				trim( $video_url ),
				$location,
				(string) $post->post_title,
				(string) get_post_type( $post ),
				(int) $post->ID
			);
		}

	}
}
