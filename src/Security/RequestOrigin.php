<?php
/**
 * Same-site checks for requests from visitors who are not logged in.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether a request came from a page on this site.
 *
 * Logged-in users are protected from cross-site requests by WordPress nonces. Guests
 * have no such token, so a hostile page could make its visitors' browsers vote. Browsers
 * send an Origin header with every POST and DELETE, which is what this check relies on.
 *
 * A form post may fall back on the Referer header, for a browser that sends no Origin.
 * The REST routes may not. WordPress lets a GET act as a POST there (?_method=POST), and
 * a GET has a Referer but no Origin: a link or an image on any page of this site, such
 * as a commenter's website link, would then make everyone who follows it vote.
 */
final class RequestOrigin {

	/**
	 * Whether the request's Origin header, or its Referer when no Origin was sent, belongs to this site.
	 *
	 * Origins added with WordPress's allowed_http_origins filter also count as this site.
	 *
	 * @param string $origin  Origin header, or ''.
	 * @param string $referer Referer header to fall back on, or '' to accept the Origin header only.
	 */
	public static function is_same_site( string $origin, string $referer = '' ): bool {
		$source = self::origin_of( '' !== $origin ? $origin : $referer );
		if ( '' === $source ) {
			return false;
		}

		$allowed = array_merge(
			[ self::origin_of( home_url() ), self::origin_of( site_url() ) ],
			array_map( [ self::class, 'origin_of' ], get_allowed_http_origins() )
		);

		return in_array( $source, $allowed, true );
	}

	/**
	 * Reduces a URL to its origin: scheme, host and port.
	 *
	 * @param string $url Absolute URL.
	 * @return string Origin such as https://example.org:8080, or '' when the URL has no scheme or host.
	 */
	private static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . $port;
	}
}
