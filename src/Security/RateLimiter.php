<?php
/**
 * Rate limiting.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Security;

use FeatureRequestSystem\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly limits per visitor and per IP address.
 *
 * Counters are fixed one-hour windows. An attempt is counted first, in one step that the
 * database or the cache does not split, and judged after. Requests that arrive at the
 * same moment therefore cannot all pass on the same count.
 *
 * With a persistent object cache the counters live there. Without one they are rows in
 * the options table, named as transients are, so that WordPress deletes them once they
 * have expired.
 *
 * The votes table's unique key, not this class, is what prevents duplicate votes.
 */
final class RateLimiter {

	/**
	 * Filter to change a limit. Receives the limit, the action and the scope ("voter" or "ip").
	 */
	public const FILTER_LIMIT = 'mwtfrs_rate_limit';

	/**
	 * Filter to change the client IP address, for sites behind a trusted proxy.
	 */
	public const FILTER_CLIENT_IP = 'mwtfrs_client_ip';

	/**
	 * Prefix of the transients that hold the counters.
	 */
	public const TRANSIENT_PREFIX = 'mwtfrs_rl_';

	/**
	 * Cache group of the counters on sites with a persistent object cache.
	 */
	private const CACHE_GROUP = 'feature-request-system-rate-limits';

	/**
	 * Allowed attempts per hour, by action and scope.
	 */
	private const LIMITS = [
		'vote'   => [
			'voter' => 30,
			'ip'    => 60,
		],
		'submit' => [
			'voter' => 5,
			'ip'    => 10,
		],
	];

	/**
	 * Counts an attempt at an action and says whether it is within the limits.
	 *
	 * Moderators are never limited. The IP address is only used as a hashed counter key.
	 *
	 * @param string $action    Action name: "vote" or "submit".
	 * @param string $voter_key Voter key of the visitor, or '' when there is none.
	 * @return int 0 when the attempt is allowed, otherwise the seconds until the limit resets.
	 */
	public static function attempt( string $action, string $voter_key ): int {
		if ( current_user_can( Capabilities::MODERATE ) ) {
			return 0;
		}

		$subjects = array_filter(
			[
				'voter' => $voter_key,
				'ip'    => self::client_ip(),
			]
		);
		$window   = gmdate( 'YmdH' );

		// The visitor's own counter comes first. Once it is over its limit, their further attempts do not use up what others at the same address may still do.
		foreach ( $subjects as $scope => $subject ) {
			$limit = (int) apply_filters( self::FILTER_LIMIT, self::LIMITS[ $action ][ $scope ], $action, $scope ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
			$key   = self::TRANSIENT_PREFIX . $action . '_' . $scope . '_' . substr( hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) ), 0, 32 ) . '_' . $window;

			if ( self::count( $key ) > $limit ) {
				return HOUR_IN_SECONDS - ( time() % HOUR_IN_SECONDS );
			}
		}

		return 0;
	}

	/**
	 * Adds one to a counter and returns what it stands at, this attempt included.
	 *
	 * A cache that cannot count answers 1: it does not stop everyone from voting, and a limit of none still holds.
	 *
	 * @param string $key Counter key.
	 */
	private static function count( string $key ): int {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::CACHE_GROUP, HOUR_IN_SECONDS );

			return max( 1, (int) wp_cache_incr( $key, 1, self::CACHE_GROUP ) );
		}

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One statement that creates or raises the counter. The options API reads, then writes, which lets simultaneous requests share a count.
			$wpdb->prepare(
				"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$wpdb->options,
				'_transient_' . $key
			)
		);

		// MySQL reports 1 row for a new counter and 2 for one that was raised. The expiry is
		// what lets WordPress delete the counter later. Writing it again changes nothing.
		if ( 1 === $inserted ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Written next to the counter, in the same way.
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %d, 'off')",
					$wpdb->options,
					'_transient_timeout_' . $key,
					time() + HOUR_IN_SECONDS
				)
			);
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read back from the database, where simultaneous requests raised it.
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, '_transient_' . $key )
		);
	}

	/**
	 * Returns what the visitor is counted by: their IP address from REMOTE_ADDR, or '' when it is not a valid address.
	 *
	 * Forwarded headers are ignored because any client can set them. Sites behind a proxy
	 * they trust can supply the real address through the mwtfrs_client_ip filter.
	 *
	 * An IPv6 address is cut down to its network, the first half of the address. One
	 * connection usually has that whole network to itself, and could otherwise use a new
	 * address for every attempt.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( self::FILTER_CLIENT_IP, $ip ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$bytes = (string) inet_pton( $ip );
		if ( 16 !== strlen( $bytes ) ) {
			return $ip;
		}

		// An IPv4 address written as IPv6 (::ffff:192.0.2.1) is that IPv4 address.
		if ( 0 === strpos( $bytes, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return (string) inet_ntop( substr( $bytes, 12 ) );
		}

		return (string) inet_ntop( substr( $bytes, 0, 8 ) . str_repeat( "\0", 8 ) );
	}
}
