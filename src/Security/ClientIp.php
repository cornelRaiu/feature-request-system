<?php
/**
 * The visitor's IP address.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the IP address of the visitor, for the rate limits and for Akismet.
 *
 * The address is REMOTE_ADDR, the end of the connection that reached the server. Headers
 * that claim another address are ignored, because any client can send them, with one
 * exception: a connection from Cloudflare's own addresses passes the visitor's address in
 * CF-Connecting-IP, and Cloudflare replaces whatever the visitor sent in that header. On a
 * site behind Cloudflare, REMOTE_ADDR is one of a few Cloudflare servers, so without this
 * strangers would share their limits.
 *
 * The address is never stored. The rate limiter keeps only a hash of it, for an hour.
 */
final class ClientIp {

	/**
	 * Filter to change the visitor's address, for sites behind a proxy other than Cloudflare.
	 * Receives the address found: REMOTE_ADDR, or the one Cloudflare passed on.
	 */
	public const FILTER = 'mwtfrs_client_ip';

	/**
	 * Filter that turns off reading the address Cloudflare passes on. Receives true.
	 */
	public const FILTER_TRUST_CLOUDFLARE = 'mwtfrs_trust_cloudflare';

	/**
	 * Cloudflare's address ranges, from https://www.cloudflare.com/ips/ on 2026-11-14.
	 * Check them before each release; they have changed rarely.
	 */
	private const CLOUDFLARE_RANGES = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	/**
	 * Returns the visitor's IP address, or '' when there is no valid one.
	 */
	public static function get(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( self::from_cloudflare( $ip ) && apply_filters( self::FILTER_TRUST_CLOUDFLARE, true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
			$visitor = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';
			if ( false !== filter_var( $visitor, FILTER_VALIDATE_IP ) ) {
				$ip = $visitor;
			}
		}

		$ip = (string) apply_filters( self::FILTER, $ip ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}

	/**
	 * Whether an address is one of Cloudflare's.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public static function from_cloudflare( string $ip ): bool {
		$address = filter_var( $ip, FILTER_VALIDATE_IP ) ? (string) inet_pton( $ip ) : '';
		if ( '' === $address ) {
			return false;
		}

		// An IPv4 address written as IPv6 (::ffff:192.0.2.1) is that IPv4 address.
		if ( 0 === strpos( $address, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$address = substr( $address, 12 );
		}

		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			list( $network, $bits ) = explode( '/', $range );
			$network                = (string) inet_pton( $network );

			if ( strlen( $network ) === strlen( $address ) && self::same_prefix( $address, $network, (int) $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether two packed addresses of the same length start with the same bits.
	 *
	 * @param string $a    Packed address.
	 * @param string $b    Packed address.
	 * @param int    $bits Number of leading bits to compare.
	 */
	private static function same_prefix( string $a, string $b, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}
}
