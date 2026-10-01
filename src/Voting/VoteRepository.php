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
	 * Returns which of the given requests a voter has voted for.
	 *
	 * @param string $voter_key   Voter key from VoterIdentity.
	 * @param int[]  $request_ids Request IDs to check.
	 * @return int[] The IDs the voter voted for.
	 */
	public static function voted_among( string $voter_key, array $request_ids ): array {
		global $wpdb;

		$request_ids = array_values( array_unique( array_filter( array_map( 'intval', $request_ids ) ) ) );
		if ( ! $request_ids ) {
			return [];
		}

		$voted = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-visitor data must not be cached.
			$wpdb->prepare(
				'SELECT request_id FROM %i WHERE voter_key = %s AND request_id IN (' . implode( ',', array_fill( 0, count( $request_ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are added.
				array_merge( [ Schema::table( Schema::VOTES ), $voter_key ], $request_ids )
			)
		);

		return array_map( 'intval', $voted );
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
	 * Returns who voted for a request: the users, newest vote first, and how many votes
	 * came from users and from guests in total.
	 *
	 * @param int $request_id Request ID.
	 * @param int $limit      Most users to return.
	 * @return array{users: int[], user_votes: int, guest_votes: int}
	 */
	public static function voters( int $request_id, int $limit ): array {
		global $wpdb;

		$users = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; shown to moderators on one screen.
			$wpdb->prepare(
				'SELECT user_id FROM %i WHERE request_id = %d AND user_id > 0 ORDER BY created_at DESC, id DESC LIMIT %d',
				Schema::table( Schema::VOTES ),
				$request_id,
				$limit
			)
		);

		$totals = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; shown to moderators on one screen.
			$wpdb->prepare(
				'SELECT COALESCE( SUM( user_id > 0 ), 0 ) AS user_votes, COALESCE( SUM( user_id = 0 ), 0 ) AS guest_votes FROM %i WHERE request_id = %d',
				Schema::table( Schema::VOTES ),
				$request_id
			)
		);

		return [
			'users'       => array_map( 'intval', $users ),
			'user_votes'  => $totals ? (int) $totals->user_votes : 0,
			'guest_votes' => $totals ? (int) $totals->guest_votes : 0,
		];
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
