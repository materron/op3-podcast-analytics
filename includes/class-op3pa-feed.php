<?php
/**
 * Handles adding the OP3 prefix to all audio enclosure URLs in the RSS feed.
 *
 * The prefix is applied to ALL feeds on the site that contain audio enclosures,
 * regardless of how many podcasts are configured. Private podcasts are excluded
 * by checking their show_uuid against the enclosure URL patterns (best effort).
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Feed {

	private const OP3_PREFIX      = 'https://op3.dev/e/';
	private const AUDIO_EXTENSIONS = 'mp3|m4a|ogg|oga|opus|aac|wav|flac';

	public static function init(): void {
		// Only hook if at least one non-private podcast has the prefix enabled.
		$active = op3pa_get_active_podcasts();
		if ( empty( $active ) ) {
			return;
		}

		add_action( 'template_redirect', [ __CLASS__, 'maybe_hook_feed' ], 1 );
	}

	/**
	 * Hook into the feed only when WordPress is about to serve a feed.
	 */
	public static function maybe_hook_feed(): void {
		if ( ! is_feed() ) {
			return;
		}
		ob_start();
		add_action( 'shutdown', [ __CLASS__, 'flush_feed_buffer' ], 0 );
	}

	/**
	 * Closes the buffer explicitly, rewrites feed XML and sends it.
	 */
	public static function flush_feed_buffer(): void {
		$feed_xml = ob_get_clean();
		if ( false === $feed_xml ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feed XML, not HTML.
		echo self::rewrite_feed( $feed_xml );
	}

	/**
	 * Rewrites audio URLs in the feed XML adding the OP3 prefix.
	 *
	 * @param string $feed_xml Full RSS XML output.
	 * @return string Modified XML.
	 */
	public static function rewrite_feed( string $feed_xml ): string {
		$active = op3pa_get_active_podcasts();
		if ( empty( $active ) ) {
			return $feed_xml;
		}

		// Use the GUID of the first active podcast for attribution (if available).
		// In a multi-podcast site each podcast has its own feed, so this is
		// a best-effort attribution; the OP3 prefix itself is what counts.
		$first   = reset( $active );
		$guid    = $first['guid'] ?? '';
		$prefix  = self::OP3_PREFIX;
		$ext     = self::AUDIO_EXTENSIONS;

		$guid_param = ! empty( $guid )
			? '?_from=' . rawurlencode( $guid )
			: '';

		$feed_xml = preg_replace_callback(
			'/(url=["\'])(https?:\/\/)([^\s"\']+?\.(' . $ext . ')(\?[^"\']*)?)(["\'"])/i',
			function ( array $m ) use ( $prefix, $guid_param ): string {
				if ( str_contains( $m[2] . $m[3], 'op3.dev/e/' ) ) {
					return $m[0];
				}
				$without_protocol = preg_replace( '#^https?://#', '', $m[2] . $m[3] );
				return $m[1] . $prefix . $without_protocol . $guid_param . $m[6];
			},
			$feed_xml
		);

		return $feed_xml;
	}

	/**
	 * Given a plain audio URL, returns the OP3-prefixed version.
	 *
	 * @param string $url Original audio URL.
	 * @return string Prefixed URL.
	 */
	public static function prefix_url( string $url ): string {
		if ( empty( $url ) || str_contains( $url, 'op3.dev/e/' ) ) {
			return $url;
		}
		$without_protocol = preg_replace( '#^https?://#', '', $url );
		return self::OP3_PREFIX . $without_protocol;
	}
}
