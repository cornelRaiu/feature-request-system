<?php
/**
 * Votes table access.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Voting;

use FeatureRequestSystem\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the votes table. One row per voter per request, enforced by a unique key.
 */
final class VoteRepository {

	/**
	 * Records a vote. Does nothing when the voter already voted for the request.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $voter_key  Voter key from VoterIdentity.
	 * @param int    $user_id    User ID, or 0 for a guest.
	 * @return bool Whether a new vote was recorded.
	 */
	public static function add( int $request_id, string $voter_key, int $user_id ): bool {
		global $wpdb;

		// The unique key on request_id and voter_key turns a repeat vote into a no-op instead of an error.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'INSERT INTO %i (request_id, voter_key, user_id, vote, created_at) VALUES (%d, %s, %d, 1, %s) ON DUPLICATE KEY UPDATE id = id',
				Schema::table( Schema::VOTES ),
				$request_id,
				$voter_key,
				$user_id,
				current_time( 'mysql', true )
			)
		);

		return 1 === $result;
	}

	/**
	 * Removes a voter's vote for a request.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $voter_key  Voter key from VoterIdentity.
	 * @return bool Whether a vote was removed.
	 */
	public static function remove( int $request_id, string $voter_key ): bool {
		global $wpdb;

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			Schema::table( Schema::VOTES ),
			[
				'request_id' => $request_id,
				'voter_key'  => $voter_key,
			],
			[ '%d', '%s' ]
		);

		return (bool) $result;
	}

	/**
	 * Removes every vote for a request.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function remove_all( int $request_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( Schema::VOTES ), [ 'request_id' => $request_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Counts the votes for a request.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function count( int $request_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the count is cached in post meta by VoteCount.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE request_id = %d', Schema::table( Schema::VOTES ), $request_id )
		);
	}
}
