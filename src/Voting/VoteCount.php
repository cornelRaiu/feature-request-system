<?php
/**
 * Stored vote counts.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Voting;

use FeatureRequestSystem\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each request's vote count in post meta, so lists can sort by votes without counting rows.
 *
 * The count is always recomputed from the votes table rather than incremented, so a missed
 * or repeated update corrects itself on the next vote.
 */
final class VoteCount {

	/**
	 * Recounts a request's votes and stores the count.
	 *
	 * @param int $request_id Request ID.
	 * @return int The number of votes.
	 */
	public static function sync( int $request_id ): int {
		$count = VoteRepository::count( $request_id );
		update_post_meta( $request_id, RequestPostType::META_VOTES, $count );

		return $count;
	}

	/**
	 * Returns the stored count without recounting.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function get( int $request_id ): int {
		return (int) get_post_meta( $request_id, RequestPostType::META_VOTES, true );
	}

	/**
	 * Recounts every request and fixes stored counts that are wrong or missing.
	 *
	 * @return array{checked: int, fixed: int}
	 */
	public static function sync_all(): array {
		global $wpdb;

		$checked = 0;
		$fixed   = 0;
		$last_id = 0;

		do {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batches over every request in any status.
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_type = %s AND ID > %d ORDER BY ID LIMIT 500',
					$wpdb->posts,
					RequestPostType::NAME,
					$last_id
				)
			);

			foreach ( $ids as $id ) {
				$last_id = (int) $id;
				++$checked;

				$stored = metadata_exists( 'post', $last_id, RequestPostType::META_VOTES ) ? self::get( $last_id ) : null;
				if ( VoteRepository::count( $last_id ) !== $stored ) {
					self::sync( $last_id );
					++$fixed;
				}
			}
		} while ( $ids );

		return [
			'checked' => $checked,
			'fixed'   => $fixed,
		];
	}
}
