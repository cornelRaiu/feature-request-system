<?php
/**
 * Stand-in for the Akismet plugin's main class.
 *
 * @package FeatureRequestSystem
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.PrefixAllGlobals -- Mirrors the Akismet plugin's class.

/**
 * Answers like Akismet without calling its API. Tests set the key and the verdict.
 */
class Akismet {

	/**
	 * API key. Empty means Akismet is not set up.
	 *
	 * @var string
	 */
	public static $api_key = '';

	/**
	 * Response body to answer with: "true" for spam, "false" otherwise.
	 *
	 * @var string
	 */
	public static $verdict = 'false';

	/**
	 * The query of the last request, or null when nothing was sent.
	 *
	 * @var array<string, mixed>|null
	 */
	public static $last_query = null;

	/**
	 * Returns the API key.
	 *
	 * @return string|false
	 */
	public static function get_api_key() {
		return '' === self::$api_key ? false : self::$api_key;
	}

	/**
	 * Records the query and returns it encoded.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function build_query( $args ): string {
		self::$last_query = $args;

		return http_build_query( $args );
	}

	/**
	 * Answers with the verdict.
	 *
	 * @param string      $request Encoded query.
	 * @param string      $path    API method.
	 * @param string|null $ip      Server IP.
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	public static function http_post( $request, $path, $ip = null ): array {
		return [ [], self::$verdict ];
	}

	/**
	 * Forgets the key, the verdict and the last query.
	 */
	public static function reset(): void {
		self::$api_key    = '';
		self::$verdict    = 'false';
		self::$last_query = null;
	}
}
