<?php
/**
 * YouTube Data API client: validates keys and returns a status result per video id.
 */

defined( 'ABSPATH' ) or die( "Oops! This is a WordPress plugin and should not be called directly.\n" );

if ( ! class_exists( 'YTF_Api_Client' ) ) {

	/**
	 * YouTube Data API returns a status result for a batch of video ids.
	 */
	class YTF_Api_Client {

		/**
		 * Option holding the YouTube Data API key.
		 */
		const KEY_OPTION = YTF_Settings::KEY_OPTION;

		/**
		 * YouTube Data API videos.list endpoint.
		 */
		const ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos';

		/**
		 * Object cache group holding one verdict per video id.
		 */
		const CACHE_GROUP = 'ytf_verdicts';

		/**
		 * Cache key version.
		 */
		const CACHE_VERSION = 'v1';

		/**
		 * Prefix for the transients that back the object cache on sites with no
		 * persistent cache backend.
		 */
		const TRANSIENT_PREFIX = 'ytf_verdict_';

		/**
		 * Status for an id the API could not get a result for
		 */
		const STATUS_ERROR = 'API ERROR';

		/**
		 * Status for an id left unchecked because the it expired.
		 */
		const STATUS_TIMEOUT = 'TIME LIMIT';

		/**
		 * Error logger.
		 */
		private $logger;

		/**
		 * Maximum video ids sent in one API call. The videos.list endpoint accepts
		 * fifty ids per request.
		 */
		private $batch = 50;

		private $quota_exceeded = false;

		/**
		 * Settings store the API key is read from.
		 */
		private $settings;

		/**
		 * Whether to run the fast check: ask for ids only and treat anything the
		 * API does not return as broken.
		 */
		private $fast = false;

		public function __construct( $logger, $settings = null, $fast = false ) {
			$this->logger   = $logger;
			$this->settings = ( $settings instanceof YTF_Settings ) ? $settings : new YTF_Settings();
			$this->fast     = (bool) $fast;
		}

		/**
		 * The parts of the videos.list. The fast check drops "status", which is
		 * most of the response body
		 */
		private function parts() {
			return $this->fast ? 'id' : 'id,status';
		}

		/**
		 * Whether the API reported exceeded the API quota
		 */
		public function quota_exceeded() {
			return $this->quota_exceeded;
		}

		/**
		 * Whether an API error payload says the API quota is exceeded.
		 */
		private function is_quota_error( $error ) {
			if ( ! is_object( $error ) ) {
				return false;
			}

			$reasons = array( 'quotaExceeded' => true, 'dailyLimitExceeded' => true );

			if ( ! empty( $error->errors ) && is_array( $error->errors ) ) {
				foreach ( $error->errors as $item ) {
					if ( isset( $item->reason ) && isset( $reasons[ $item->reason ] ) ) {
						return true;
					}
				}
			}

			return isset( $error->status ) && 'RESOURCE_EXHAUSTED' === $error->status;
		}

		/**
		 * The saved API key, trimmed
		 */
		public static function saved_key() {
			$settings = new YTF_Settings();
			return $settings->api_key();
		}

		/**
		 * Whether an API key is saved
		 */
		public static function is_ready() {
			return '' !== self::saved_key();
		}

		/**
		 * Check whether an API key is accepted by the YouTube Data API.
		 */
		public function validate_key( $api_key ) {
			$api_key = trim( (string) $api_key );
			if ( '' === $api_key ) {
				return array( 'valid' => false, 'message' => __( 'No API key entered.', 'youtube-forge' ) );
			}

			$url = self::ENDPOINT . '?' . http_build_query( array(
				'part' => 'status',
				'id'   => 'dQw4w9WgXcQ',
				'key'  => $api_key,
			) );

			$response = wp_remote_get( $url, array( 'sslverify' => true, 'timeout' => YTF_API_TIMEOUT ) );

			if ( is_wp_error( $response ) ) {
				return array( 'valid' => false, 'message' => $response->get_error_message() );
			}

			$data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $data->error ) ) {
				$message = isset( $data->error->message ) ? $data->error->message : __( 'The API rejected this key.', 'youtube-forge' );
				return array( 'valid' => false, 'message' => $message );
			}

			if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				return array( 'valid' => true, 'message' => __( 'API key is valid.', 'youtube-forge' ) );
			}

			return array( 'valid' => false, 'message' => __( 'Unexpected response from the YouTube API.', 'youtube-forge' ) );
		}

		/**
		 * Check a batch of video ids with the saved API key.
		 */
		public function check_ids( array $ids, $deadline = 0 ) {
			$verdict = array();
			$ids     = array_values( array_unique( array_map( 'strval', $ids ) ) );
			$api_key = $this->settings->api_key();
			if ( empty( $ids ) || '' === $api_key ) {
				return $verdict;
			}

			$pending = array();
			$cached  = $this->cached_verdicts( $ids );
			foreach ( $ids as $id ) {
				if ( isset( $cached[ $id ] ) ) {
					$verdict[ $id ] = $cached[ $id ];
				} else {
					$pending[] = $id;
				}
			}

			$called = false;

			foreach ( array_chunk( $pending, $this->batch ) as $chunk ) {
				$out_of_time = ( $called && $deadline > 0 && microtime( true ) >= (float) $deadline );

				if ( $this->quota_exceeded || $out_of_time ) {
					foreach ( $chunk as $id ) {
						$verdict[ (string) $id ] = array( 'broken' => 0, 'status' => self::STATUS_TIMEOUT );
					}
					continue;
				}

				$called = true;

				$url = self::ENDPOINT . '?' . http_build_query( array(
					'part' => $this->parts(),
					'id'   => implode( ',', $chunk ),
					'key'  => $api_key,
				) );
				$data = $this->request( $url );

				if ( ! $data || isset( $data->error ) ) {
					if ( isset( $data->error ) ) {
						$message = isset( $data->error->message ) ? $data->error->message : wp_json_encode( $data->error );
						$this->logger->add_error( sprintf( __( '%s API error (%s). Call was %s', 'youtube-forge' ), __FUNCTION__, $message, $this->redact_key( $url ) ) );

						if ( $this->is_quota_error( $data->error ) ) {
							$this->quota_exceeded = true;
						}
					}
					foreach ( $chunk as $id ) {
						$verdict[ (string) $id ] = array( 'broken' => 0, 'status' => self::STATUS_ERROR );
					}
					continue;
				}

				$items = isset( $data->items ) ? $data->items : array();
				foreach ( $items as $video ) {
					$verdict[ (string) $video->id ] = $this->fast
						? array( 'broken' => 0, 'status' => 'FOUND' )
						: $this->classify( $video );
				}

				foreach ( $chunk as $id ) {
					if ( ! isset( $verdict[ (string) $id ] ) ) {
						$verdict[ (string) $id ] = array( 'broken' => 1, 'status' => 'BROKEN' );
					}
					$this->cache_verdict( (string) $id, $verdict[ (string) $id ] );
				}
			}

			return $verdict;
		}

		/**
		 * Cache key for one video id.
		 */
		private function cache_key( $video_id ) {
			return self::CACHE_VERSION . $this->cache_scope() . '_' . $video_id;
		}

		/**
		 * Keeps the two modes in separate cache namespaces.
		 */
		private function cache_scope() {
			return $this->fast ? 'f' : '';
		}

		/**
		 * How long a result stays cached.
		 */
		private function cache_ttl() {
			return (int) apply_filters( 'ytf_verdict_cache_ttl', DAY_IN_SECONDS );
		}

		/**
		 * Transient key for one video id.
		 */
		private function transient_key( $video_id ) {
			return self::TRANSIENT_PREFIX . self::CACHE_VERSION . $this->cache_scope() . '_' . $video_id;
		}

		/**
		 * Whether results are also written to transients. Without a persistent
		 * object cache backend the object cache lasts one request, and a scan
		 * runs a request per chunk, so the same video id would be re-fetched
		 * every time it appears in a different chunk.
		 */
		private function use_transients() {
			return (bool) apply_filters( 'ytf_verdict_transient_fallback', ! wp_using_ext_object_cache() );
		}

		/**
		 * Prime the option cache for a set of results
		 */
		private function prime_transients( $ids ) {
			if ( ! function_exists( 'wp_prime_option_caches' ) ) {
				return;
			}

			$names = array();
			foreach ( $ids as $id ) {
				$key     = $this->transient_key( $id );
				$names[] = '_transient_' . $key;
				$names[] = '_transient_timeout_' . $key;
			}

			wp_prime_option_caches( $names );
		}

		/**
		 * Read whatever results are already cached for a set of ids
		 */
		private function cached_verdicts( $ids ) {
			$found = array();

			$ttl = $this->cache_ttl();
			if ( $ttl < 1 ) {
				return $found;
			}

			$missing = array();

			if ( function_exists( 'wp_cache_get_multiple' ) ) {
				$keys = array();
				foreach ( $ids as $id ) {
					$keys[] = $this->cache_key( $id );
				}

				$values = wp_cache_get_multiple( $keys, self::CACHE_GROUP );
				foreach ( $ids as $index => $id ) {
					$key = $keys[ $index ];
					if ( isset( $values[ $key ] ) && is_array( $values[ $key ] ) ) {
						$found[ $id ] = $values[ $key ];
					} else {
						$missing[] = $id;
					}
				}
			} else {
				foreach ( $ids as $id ) {
					$value = wp_cache_get( $this->cache_key( $id ), self::CACHE_GROUP );
					if ( is_array( $value ) ) {
						$found[ $id ] = $value;
					} else {
						$missing[] = $id;
					}
				}
			}

			if ( empty( $missing ) || ! $this->use_transients() ) {
				return $found;
			}

			$this->prime_transients( $missing );

			foreach ( $missing as $id ) {
				$value = get_transient( $this->transient_key( $id ) );
				if ( is_array( $value ) ) {
					$found[ $id ] = $value;
					wp_cache_set( $this->cache_key( $id ), $value, self::CACHE_GROUP, $ttl );
				}
			}

			return $found;
		}

		/**
		 * Cache one result
		 */
		private function cache_verdict( $video_id, $result ) {
			$status = isset( $result['status'] ) ? $result['status'] : '';
			if ( self::STATUS_ERROR === $status || self::STATUS_TIMEOUT === $status ) {
				return;
			}

			$ttl = $this->cache_ttl();
			if ( $ttl < 1 ) {
				return;
			}

			wp_cache_set( $this->cache_key( $video_id ), $result, self::CACHE_GROUP, $ttl );

			if ( $this->use_transients() ) {
				set_transient( $this->transient_key( $video_id ), $result, $ttl );
			}
		}

		/**
		 * A single API video result.
		 */
		private function classify( $video ) {
			$status = isset( $video->status ) ? $video->status : null;
			if ( ! is_object( $status ) ) {
				return array( 'broken' => 1, 'status' => 'UNAVAILABLE' );
			}

			$privacy    = isset( $status->privacyStatus ) ? $status->privacyStatus : '';
			$embeddable = isset( $status->embeddable ) ? $status->embeddable : null;
			$upload     = isset( $status->uploadStatus ) ? $status->uploadStatus : '';

			if ( 'private' === $privacy ) {
				return array( 'broken' => 1, 'status' => strtoupper( $privacy ) );
			}

			if ( true !== $embeddable ) {
				return array( 'broken' => 1, 'status' => 'NOT EMBEDDABLE' );
			}

			if ( 'processed' === $upload || 'uploaded' === $upload ) {
				$label = 'FOUND';
				if ( 'unlisted' === $privacy ) {
					$label = 'UNLISTED';
				}
				return array( 'broken' => 0, 'status' => $label );
			}

			$label = '' !== $upload ? strtoupper( $upload ) : 'UNAVAILABLE';
			return array( 'broken' => 1, 'status' => $label );
		}

		/**
		 * Strip the API key from a request URL so it never reaches the error log
		 */
		private function redact_key( $url ) {
			return add_query_arg( 'key', 'REDACTED', remove_query_arg( 'key', (string) $url ) );
		}

		/**
		 * Perform an HTTP GET with one retry on timeout.
		 */
		private function request( $url, $headers = null ) {
			if ( empty( $headers ) ) {
				$headers = array( 'User-Agent' => 'YouTube Forge;' );
			}

			$response = wp_remote_get( $url, array(
				'headers'    => $headers,
				'decompress' => false,
				'sslverify'  => true,
				'timeout'    => YTF_API_TIMEOUT,
			) );

			if ( is_wp_error( $response ) && strpos( $response->get_error_message(), 'timed out' ) !== false ) {
				$response = wp_remote_get( $url, array( 'decompress' => false, 'sslverify' => true, 'timeout' => YTF_API_TIMEOUT ) );
			}

			if ( is_wp_error( $response ) ) {
				$this->logger->add_error( sprintf( __( 'WP error in %s (%s): %s', 'youtube-forge' ), __FUNCTION__, $this->redact_key( $url ), $response->get_error_message() ) );
				return null;
			}

			return json_decode( wp_remote_retrieve_body( $response ) );
		}

	}
}
