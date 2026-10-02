<?php
/**
 * Subscriptions table access.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use FeatureRequestSystem\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the subscriptions table. One row per user per request they
 * follow, enforced by a unique key.
 *
 * @phpstan-type Subscription array{id: int, request_id: int, user_id: int, token: string, source: string, created_at: string}
 */
final class SubscriptionRepository {

	/**
	 * The user follows the request because they voted for it.
	 */
	public const SOURCE_VOTE = 'vote';

	/**
	 * The user chose to follow the request.
	 */
	public const SOURCE_FOLLOW = 'follow';

	/**
	 * The user follows the request because they suggested it.
	 */
	public const SOURCE_AUTHOR = 'author';

	/**
	 * Makes a user follow a request. Does nothing when they already follow it, except
	 * that choosing to follow replaces a weaker reason, so that taking back a vote
	 * later does not end it.
	 *
	 * @param int    $request_id Request ID.
	 * @param int    $user_id    User ID.
	 * @param string $source     Why the user follows the request, one of the SOURCE constants.
	 * @return bool Whether the user started following the request.
	 */
	public static function add( int $request_id, int $user_id, string $source ): bool {
		global $wpdb;

		// The unique key on request_id and user_id turns a repeat into an update instead of an error.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'INSERT INTO %i (request_id, user_id, token, source, created_at) VALUES (%d, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE source = IF( %d, %s, source )',
				Schema::table( Schema::SUBSCRIPTIONS ),
				$request_id,
				$user_id,
				wp_generate_password( 32, false ),
				$source,
				current_time( 'mysql', true ),
				(int) ( self::SOURCE_FOLLOW === $source ),
				$source
			)
		);

		return 1 === $result;
	}

	/**
	 * Makes a user stop following a request.
	 *
	 * @param int    $request_id  Request ID.
	 * @param int    $user_id     User ID.
	 * @param string $only_source Only stop when this is why the user follows the request. '' stops in any case.
	 * @return bool Whether the user was following the request and no longer is.
	 */
	public static function remove( int $request_id, int $user_id, string $only_source = '' ): bool {
		global $wpdb;

		$where = [
			'request_id' => $request_id,
			'user_id'    => $user_id,
		];
		if ( '' !== $only_source ) {
			$where['source'] = $only_source;
		}

		return (bool) $wpdb->delete( Schema::table( Schema::SUBSCRIPTIONS ), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Removes every follower of a request.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function remove_request( int $request_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( Schema::SUBSCRIPTIONS ), [ 'request_id' => $request_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Makes a user stop following every request.
	 *
	 * @param int $user_id User ID.
	 * @return int How many requests the user was following.
	 */
	public static function remove_user( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->delete( Schema::table( Schema::SUBSCRIPTIONS ), [ 'user_id' => $user_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Returns a user's subscription to a request, or null when they do not follow it.
	 *
	 * @param int $request_id Request ID.
	 * @param int $user_id    User ID.
	 * @return array<string, int|string>|null
	 * @phpstan-return Subscription|null
	 */
	public static function find( int $request_id, int $user_id ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-user data must not be cached.
			$wpdb->prepare(
				'SELECT id, request_id, user_id, token, source, created_at FROM %i WHERE request_id = %d AND user_id = %d',
				Schema::table( Schema::SUBSCRIPTIONS ),
				$request_id,
				$user_id
			)
		);

		return self::rows( $rows )[0] ?? null;
	}

	/**
	 * Returns the subscription that an unsubscribe link belongs to, or null when the link is no longer valid.
	 *
	 * @param string $token Token from the link.
	 * @return array<string, int|string>|null
	 * @phpstan-return Subscription|null
	 */
	public static function find_by_token( string $token ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; a secret must not be cached.
			$wpdb->prepare(
				'SELECT id, request_id, user_id, token, source, created_at FROM %i WHERE token = %s',
				Schema::table( Schema::SUBSCRIPTIONS ),
				$token
			)
		);

		// The database compares text without regard to case. The token must match exactly.
		foreach ( self::rows( $rows ) as $subscription ) {
			if ( hash_equals( $subscription['token'], $token ) ) {
				return $subscription;
			}
		}

		return null;
	}

	/**
	 * Returns the IDs of a request's subscriptions, oldest first.
	 *
	 * @param int $request_id Request ID.
	 * @return int[]
	 */
	public static function ids( int $request_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT id FROM %i WHERE request_id = %d ORDER BY id ASC', Schema::table( Schema::SUBSCRIPTIONS ), $request_id )
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Returns a request's subscriptions whose IDs lie in a range, both ends included.
	 *
	 * @param int $request_id Request ID.
	 * @param int $first_id   Lowest subscription ID.
	 * @param int $last_id    Highest subscription ID.
	 * @return array<int, array<string, int|string>>
	 * @phpstan-return list<Subscription>
	 */
	public static function in_range( int $request_id, int $first_id, int $last_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; read at the moment the emails go out.
			$wpdb->prepare(
				'SELECT id, request_id, user_id, token, source, created_at FROM %i WHERE request_id = %d AND id BETWEEN %d AND %d ORDER BY id ASC',
				Schema::table( Schema::SUBSCRIPTIONS ),
				$request_id,
				$first_id,
				$last_id
			)
		);

		return self::rows( $rows );
	}

	/**
	 * Counts the followers of a request.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function count( int $request_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE request_id = %d', Schema::table( Schema::SUBSCRIPTIONS ), $request_id )
		);
	}

	/**
	 * Returns the subscriptions of a user, newest first, a page at a time.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Most subscriptions to return.
	 * @param int $offset  Subscriptions to skip.
	 * @return array<int, array<string, int|string>>
	 * @phpstan-return list<Subscription>
	 */
	public static function of_user( int $user_id, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-user data must not be cached.
			$wpdb->prepare(
				'SELECT id, request_id, user_id, token, source, created_at FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
				Schema::table( Schema::SUBSCRIPTIONS ),
				$user_id,
				$limit,
				$offset
			)
		);

		return self::rows( $rows );
	}

	/**
	 * Counts the requests a user follows.
	 *
	 * @param int $user_id User ID.
	 */
	public static function count_of_user( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-user data must not be cached.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', Schema::table( Schema::SUBSCRIPTIONS ), $user_id )
		);
	}

	/**
	 * Turns database rows into subscriptions with typed fields.
	 *
	 * @param mixed $rows Rows from $wpdb->get_results().
	 * @return array<int, array<string, int|string>>
	 * @phpstan-return list<Subscription>
	 */
	private static function rows( $rows ): array {
		$subscriptions = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$subscriptions[] = [
				'id'         => (int) $row->id,
				'request_id' => (int) $row->request_id,
				'user_id'    => (int) $row->user_id,
				'token'      => (string) $row->token,
				'source'     => (string) $row->source,
				'created_at' => (string) $row->created_at,
			];
		}

		return $subscriptions;
	}
}
