<?php
/**
 * Vote limits per board.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Voting;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\StatusTaxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * How many votes a person has left on a board that limits them.
 *
 * Only votes on requests that are still open for voting count. A vote comes back when its
 * request is shipped or declined, or leaves the board, so people spend their votes on
 * what is still to be decided.
 */
final class VoteLimit {

	/**
	 * How many votes a voter has left on a board, or null when the board has no limit.
	 *
	 * @param int    $board_id  Board term ID.
	 * @param string $voter_key Voter key from VoterIdentity, or '' for a guest who has not voted yet.
	 */
	public static function left( int $board_id, string $voter_key ): ?int {
		$limit = BoardSettings::vote_limit( $board_id );
		if ( ! $limit ) {
			return null;
		}

		return max( 0, $limit - self::used( $board_id, $voter_key ) );
	}

	/**
	 * Whether a voter has given more votes on a board than it allows.
	 *
	 * @param int    $board_id  Board term ID.
	 * @param string $voter_key Voter key from VoterIdentity.
	 */
	public static function exceeded( int $board_id, string $voter_key ): bool {
		$limit = BoardSettings::vote_limit( $board_id );

		return $limit && self::used( $board_id, $voter_key ) > $limit;
	}

	/**
	 * How many of a voter's votes count against a board's limit.
	 *
	 * @param int    $board_id  Board term ID.
	 * @param string $voter_key Voter key from VoterIdentity.
	 */
	private static function used( int $board_id, string $voter_key ): int {
		return '' === $voter_key ? 0 : VoteRepository::count_open_on_board( $voter_key, $board_id, StatusTaxonomy::closed_ids() );
	}
}
