<?php
/**
 * The parts of the Akismet plugin that the plugin calls, so PHPStan can check them.
 *
 * @package FeatureRequestSystem
 */

/**
 * Akismet's main class.
 */
class Akismet {

	/**
	 * Returns the site's Akismet API key, or false when none is set.
	 *
	 * @return string|false
	 */
	public static function get_api_key() {
		return false;
	}

	/**
	 * Sends a request to the Akismet API.
	 *
	 * @param string $request Query string to send.
	 * @param string $path    API method, such as "comment-check".
	 * @param string $ip      Server IP to use, if any.
	 * @return array{0: mixed, 1: string} Response headers and body. Both are empty when the request fails.
	 */
	public static function http_post( $request, $path, $ip = null ) {
		return [ [], '' ];
	}

	/**
	 * Builds a query string from an array.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function build_query( $args ): string {
		return '';
	}
}
