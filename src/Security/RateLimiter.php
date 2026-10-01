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
 * Hourly limits per visitor and per IP address, counted in transients.
 *
 * Counters are fixed one-hour windows. They are abuse protection, not exact accounting:
 * two requests arriving at the same moment may both pass. The votes table's unique key,
 * not this class, is what prevents duplicate votes.
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
	 * Counts an attempt at an action, unless the visitor is already over a limit.
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
		$counters = [];

		foreach ( $subjects as $scope => $subject ) {
			$limit = (int) apply_filters( self::FILTER_LIMIT, self::LIMITS[ $action ][ $scope ], $action, $scope ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
			$key   = self::TRANSIENT_PREFIX . $action . '_' . $scope . '_' . substr( hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) ), 0, 32 ) . '_' . $window;
			$count = (int) get_transient( $key );

			if ( $count >= $limit ) {
				return HOUR_IN_SECONDS - ( time() % HOUR_IN_SECONDS );
			}
			$counters[ $key ] = $count;
		}

		foreach ( $counters as $key => $count ) {
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}

		return 0;
	}

	/**
	 * Returns the visitor's IP address from REMOTE_ADDR, or '' when it is not a valid address.
	 *
	 * Forwarded headers are ignored because any client can set them. Sites behind a proxy
	 * they trust can supply the real address through the mwtfrs_client_ip filter.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( self::FILTER_CLIENT_IP, $ip ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
