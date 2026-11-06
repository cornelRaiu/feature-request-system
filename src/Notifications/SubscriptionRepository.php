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
 * follow, enforced by a unique key. A guest who confirmed their email follows requests
 * the same way: their rows hold their guest ID, and 0 as the user ID.
 *
 * @phpstan-type Subscription array{id: int, request_id: int, user_id: int, guest_id: int, token: string, source: string, created_at: string}
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
	 * Columns read for a subscription.
	 */
	private const COLUMNS = 'id, request_id, user_id, guest_id, token, source, created_at';

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
		return $user_id > 0 && self::insert( $request_id, $user_id, 0, $source );
	}

	/**
	 * Makes a guest who confirmed their email follow a request, as add() does for a user.
	 *
	 * @param int    $request_id Request ID.
	 * @param int    $guest_id   Guest ID.
	 * @param string $source     Why the guest follows the request, one of the SOURCE constants.
	 * @return bool Whether the guest started following the request.
	 */
	public static function add_guest( int $request_id, int $guest_id, string $source ): bool {
		return $guest_id > 0 && self::insert( $request_id, 0, $guest_id, $source );
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

		// User ID 0 is every guest.
		if ( $user_id <= 0 ) {
			return false;
		}

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
	 * Makes a guest stop following a request, as remove() does for a user.
	 *
	 * @param int    $request_id  Request ID.
	 * @param int    $guest_id    Guest ID.
	 * @param string $only_source Only stop when this is why the guest follows the request. '' stops in any case.
	 * @return bool Whether the guest was following the request and no longer is.
	 */
	public static function remove_guest( int $request_id, int $guest_id, string $only_source = '' ): bool {
		global $wpdb;

		if ( $guest_id <= 0 ) {
			return false;
		}

		$where = [
			'request_id' => $request_id,
			'guest_id'   => $guest_id,
		];
		if ( '' !== $only_source ) {
			$where['source'] = $only_source;
		}

		return (bool) $wpdb->delete( Schema::table( Schema::SUBSCRIPTIONS ), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Removes one subscription, whoever it belongs to.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return bool Whether it was removed.
	 */
	public static function remove_by_id( int $subscription_id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( Schema::SUBSCRIPTIONS ), [ 'id' => $subscription_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
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

		if ( $user_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->delete( Schema::table( Schema::SUBSCRIPTIONS ), [ 'user_id' => $user_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Makes a guest stop following every request.
	 *
	 * @param int $guest_id Guest ID.
	 * @return int How many requests the guest was following.
	 */
	public static function remove_all_of_guest( int $guest_id ): int {
		global $wpdb;

		if ( $guest_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->delete( Schema::table( Schema::SUBSCRIPTIONS ), [ 'guest_id' => $guest_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
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
		return $user_id > 0 ? self::find_by( $request_id, 'user_id', $user_id ) : null;
	}

	/**
	 * Returns a guest's subscription to a request, or null when they do not follow it.
	 *
	 * @param int $request_id Request ID.
	 * @param int $guest_id   Guest ID.
	 * @return array<string, int|string>|null
	 * @phpstan-return Subscription|null
	 */
	public static function find_guest( int $request_id, int $guest_id ): ?array {
		return $guest_id > 0 ? self::find_by( $request_id, 'guest_id', $guest_id ) : null;
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
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE token = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
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
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE request_id = %d AND id BETWEEN %d AND %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
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
	 * Counts the followers of several requests in one query.
	 *
	 * @param int[] $request_ids Request IDs.
	 * @return array<int, int> Followers by request ID. Requests without followers are left out.
	 */
	public static function counts( array $request_ids ): array {
		global $wpdb;

		$request_ids = array_values( array_unique( array_filter( array_map( 'intval', $request_ids ) ) ) );
		if ( ! $request_ids ) {
			return [];
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; read for an export.
			$wpdb->prepare(
				'SELECT request_id, COUNT(*) AS followers FROM %i WHERE request_id IN (' . implode( ',', array_fill( 0, count( $request_ids ), '%d' ) ) . ') GROUP BY request_id', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are added.
				array_merge( [ Schema::table( Schema::SUBSCRIPTIONS ) ], $request_ids )
			)
		);

		$counts = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (int) $row->request_id ] = (int) $row->followers;
		}

		return $counts;
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
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
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
	 * Returns the subscriptions of a guest, newest first, a page at a time.
	 *
	 * @param int $guest_id Guest ID.
	 * @param int $limit    Most subscriptions to return.
	 * @param int $offset   Subscriptions to skip.
	 * @return array<int, array<string, int|string>>
	 * @phpstan-return list<Subscription>
	 */
	public static function of_guest( int $guest_id, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-visitor data must not be cached.
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE guest_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
				Schema::table( Schema::SUBSCRIPTIONS ),
				$guest_id,
				$limit,
				$offset
			)
		);

		return self::rows( $rows );
	}

	/**
	 * Counts the requests a guest follows.
	 *
	 * @param int $guest_id Guest ID.
	 */
	public static function count_of_guest( int $guest_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-visitor data must not be cached.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE guest_id = %d', Schema::table( Schema::SUBSCRIPTIONS ), $guest_id )
		);
	}

	/**
	 * Adds a subscription for a user or a guest.
	 *
	 * @param int    $request_id Request ID.
	 * @param int    $user_id    User ID, or 0 for a guest.
	 * @param int    $guest_id   Guest ID, or 0 for a user.
	 * @param string $source     Why they follow the request, one of the SOURCE constants.
	 * @return bool Whether they started following the request.
	 */
	private static function insert( int $request_id, int $user_id, int $guest_id, string $source ): bool {
		global $wpdb;

		// The unique key on request_id, user_id and guest_id turns a repeat into an update instead of an error.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'INSERT INTO %i (request_id, user_id, guest_id, token, source, created_at) VALUES (%d, %d, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE source = IF( %d, %s, source )',
				Schema::table( Schema::SUBSCRIPTIONS ),
				$request_id,
				$user_id,
				$guest_id,
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
	 * Returns the subscription of a user or a guest to a request, or null.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $column     "user_id" or "guest_id".
	 * @param int    $id         The user's or the guest's ID.
	 * @return array<string, int|string>|null
	 * @phpstan-return Subscription|null
	 */
	private static function find_by( int $request_id, string $column, int $id ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-user data must not be cached.
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . ' FROM %i WHERE request_id = %d AND %i = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
				Schema::table( Schema::SUBSCRIPTIONS ),
				$request_id,
				$column,
				$id
			)
		);

		return self::rows( $rows )[0] ?? null;
	}

	/**
	 * Moves every follower of one request to another. A user or a guest who followed both keeps
	 * the stronger of their two reasons: choosing to follow, then suggesting, then voting.
	 *
	 * The subscriptions keep their tokens, so the links in emails sent before still work.
	 *
	 * @param int $from_id Request the followers are taken from.
	 * @param int $to_id   Request the followers go to.
	 */
	public static function move( int $from_id, int $to_id ): void {
		global $wpdb;

		$table = Schema::table( Schema::SUBSCRIPTIONS );

		// Someone who follows both: the reason from the first request wins where it is the stronger one.
		// A guest's subscriptions have user_id 0, so the guest's ID is matched too.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'UPDATE %i AS kept INNER JOIN %i AS moved ON moved.user_id = kept.user_id AND moved.guest_id = kept.guest_id AND moved.request_id = %d SET kept.source = moved.source WHERE kept.request_id = %d AND FIELD( moved.source, %s, %s, %s ) > FIELD( kept.source, %s, %s, %s )',
				$table,
				$table,
				$from_id,
				$to_id,
				self::SOURCE_VOTE,
				self::SOURCE_AUTHOR,
				self::SOURCE_FOLLOW,
				self::SOURCE_VOTE,
				self::SOURCE_AUTHOR,
				self::SOURCE_FOLLOW
			)
		);

		// IGNORE leaves the subscriptions that the unique key on request_id, user_id and guest_id refuses: those were handled above.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'UPDATE IGNORE %i SET request_id = %d WHERE request_id = %d', $table, $to_id, $from_id )
		);
		self::remove_request( $from_id );
	}

	/**
	 * Returns the IDs of the users who follow a request.
	 *
	 * @param int $request_id Request ID.
	 * @return int[]
	 */
	public static function user_ids( int $request_id ): array {
		return self::follower_ids( $request_id, 'user_id' );
	}

	/**
	 * Returns the IDs of the guests who follow a request.
	 *
	 * @param int $request_id Request ID.
	 * @return int[]
	 */
	public static function guest_ids( int $request_id ): array {
		return self::follower_ids( $request_id, 'guest_id' );
	}

	/**
	 * Returns the users' or the guests' IDs from a request's subscriptions, oldest first.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $column     "user_id" or "guest_id".
	 * @return int[]
	 */
	private static function follower_ids( int $request_id, string $column ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT %i FROM %i WHERE request_id = %d AND %i > 0 ORDER BY id ASC', $column, Schema::table( Schema::SUBSCRIPTIONS ), $request_id, $column )
		);

		return array_map( 'intval', $ids );
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
				'guest_id'   => (int) $row->guest_id,
				'token'      => (string) $row->token,
				'source'     => (string) $row->source,
				'created_at' => (string) $row->created_at,
			];
		}

		return $subscriptions;
	}
}
