<?php
/**
 * Vote thresholds.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Voting;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Moves a request to another status when it reaches the number of votes its board sets,
 * such as from Open to Under Review at 50 votes.
 *
 * A request is moved once. A moderator who moves it back is not overruled by the next
 * vote. The move goes through wp_set_object_terms(), like every status change, so it is
 * announced and its followers are emailed as usual.
 */
final class VoteThreshold {

	/**
	 * Post meta set when the threshold moved the request, holding the time it did.
	 */
	public const META = '_mwtfrs_threshold_reached';

	/**
	 * Listens to new votes, after the voter started to follow the request, so the voter is
	 * among the followers who hear of the move.
	 */
	public static function add_hooks(): void {
		add_action( VoteService::ACTION_CAST, [ self::class, 'apply' ], 20 );
	}

	/**
	 * Moves a request that a new vote took to its board's threshold.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function apply( int $request_id ): void {
		$board     = RequestTerms::term_of( $request_id, BoardTaxonomy::NAME );
		$threshold = $board ? BoardSettings::threshold( $board->term_id ) : null;
		if ( ! $threshold || VoteCount::get( $request_id ) < $threshold['votes'] ) {
			return;
		}

		$status = RequestTerms::term_of( $request_id, StatusTaxonomy::NAME );
		if ( ! $status || $status->term_id !== $threshold['from'] ) {
			return;
		}

		// Marked first, so the request is moved once, however many votes arrive together.
		if ( ! add_post_meta( $request_id, self::META, time(), true ) ) {
			return;
		}

		// The board's rule makes the change, not the voter. With nobody as the one who made
		// it, the voter is emailed like every other follower.
		$voter = get_current_user_id();
		wp_set_current_user( 0 );
		wp_set_object_terms( $request_id, $threshold['to'], StatusTaxonomy::NAME );
		wp_set_current_user( $voter );
	}
}
