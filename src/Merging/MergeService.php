<?php
/**
 * Merging duplicate requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Merging;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteRepository;
use FeatureRequestSystem\Voting\VoteService;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The rules for merging a request into another that asks for the same thing. Every way
 * of merging goes through this class: the edit screen, the REST API and any code.
 *
 * In one step, the votes and the followers move to the other request, the person who
 * suggested the duplicate follows it too, and the duplicate becomes merged: its address
 * leads to the other request, and its comments stay with it. Merging cannot be undone.
 */
final class MergeService {

	/**
	 * The request to merge into is the request itself.
	 */
	public const ERROR_INTO_ITSELF = 'mwtfrs_merge_into_itself';

	/**
	 * The request to merge into was merged itself.
	 */
	public const ERROR_INTO_MERGED = 'mwtfrs_merge_into_merged';

	/**
	 * The request to merge into is on another board.
	 */
	public const ERROR_OTHER_BOARD = 'mwtfrs_merge_other_board';

	/**
	 * The request to merge into does not exist.
	 */
	public const ERROR_INVALID_TARGET = 'mwtfrs_merge_invalid_target';

	/**
	 * The request to merge into is not published, so nobody would see the votes and followers it gets.
	 */
	public const ERROR_UNPUBLISHED_TARGET = 'mwtfrs_merge_unpublished_target';

	/**
	 * The request was merged already. Merging cannot be undone, nor done twice.
	 */
	public const ERROR_ALREADY_MERGED = 'mwtfrs_already_merged';

	/**
	 * Action fired after a merge. Receives the merged request's ID, the ID of the request
	 * it was merged into and the IDs of the users who followed the merged request, who
	 * now follow the other one.
	 */
	public const ACTION_MERGED = 'mwtfrs_request_merged';

	/**
	 * Filter for who may merge requests. Receives whether the user may, which by default
	 * is whether they can edit others' requests, and the user's ID.
	 */
	public const FILTER_CAN_MERGE = 'mwtfrs_can_merge';

	/**
	 * Post statuses of a request that cannot be merged, because it is not really there.
	 */
	private const ABSENT = [ 'trash', 'auto-draft', 'inherit' ];

	/**
	 * Whether the current user may merge requests.
	 */
	public static function can_merge(): bool {
		$user_id = get_current_user_id();

		return (bool) apply_filters( self::FILTER_CAN_MERGE, $user_id && current_user_can( Capabilities::MODERATE ), $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
	}

	/**
	 * Checks whether a request can be merged into another.
	 *
	 * @param int $request_id ID of the duplicate.
	 * @param int $target_id  ID of the request to merge it into.
	 * @return true|WP_Error
	 */
	public static function check( int $request_id, int $target_id ) {
		$request = get_post( $request_id );
		if ( ! self::is_request( $request ) || in_array( $request->post_status, self::ABSENT, true ) ) {
			return self::error( VoteService::ERROR_NOT_FOUND, 404 );
		}
		if ( MergedStatus::NAME === $request->post_status ) {
			return self::error( self::ERROR_ALREADY_MERGED, 409 );
		}
		if ( $request_id === $target_id ) {
			return self::error( self::ERROR_INTO_ITSELF, 400 );
		}

		$target = get_post( $target_id );
		if ( ! self::is_request( $target ) || in_array( $target->post_status, self::ABSENT, true ) ) {
			return self::error( self::ERROR_INVALID_TARGET, 400 );
		}
		if ( MergedStatus::NAME === $target->post_status ) {
			return self::error( self::ERROR_INTO_MERGED, 400 );
		}
		// Its votes and followers would be somewhere nobody can see them.
		if ( 'publish' !== $target->post_status ) {
			return self::error( self::ERROR_UNPUBLISHED_TARGET, 400 );
		}

		$board        = RequestTerms::term_of( $request_id, BoardTaxonomy::NAME );
		$target_board = RequestTerms::term_of( $target_id, BoardTaxonomy::NAME );
		if ( ( $board ? $board->term_id : 0 ) !== ( $target_board ? $target_board->term_id : 0 ) ) {
			return self::error( self::ERROR_OTHER_BOARD, 400 );
		}

		return true;
	}

	/**
	 * Merges a request into another.
	 *
	 * @param int $request_id ID of the duplicate.
	 * @param int $target_id  ID of the request to merge it into.
	 * @return array{id: int, merged_into: int, votes: int, url: string}|WP_Error The request it now lives on and its
	 *                                                                          vote count, or why the merge was refused.
	 */
	public static function merge( int $request_id, int $target_id ) {
		$allowed = self::check( $request_id, $target_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// The person who suggested the duplicate follows where its votes go, as everyone who followed it does.
		Subscriptions::follow_submitted( $request_id );
		$followers = SubscriptionRepository::user_ids( $request_id );

		VoteRepository::move( $request_id, $target_id );
		SubscriptionRepository::move( $request_id, $target_id );

		// The meta first: MergedStatus keeps a request with it in the merged status.
		update_post_meta( $request_id, MergedStatus::META_INTO, $target_id );
		wp_update_post(
			[
				'ID'          => $request_id,
				'post_status' => MergedStatus::NAME,
			]
		);

		VoteCount::sync( $request_id );
		$votes = VoteCount::sync( $target_id );

		do_action( self::ACTION_MERGED, $request_id, $target_id, $followers ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		return [
			'id'          => $request_id,
			'merged_into' => $target_id,
			'votes'       => $votes,
			'url'         => (string) get_permalink( $target_id ),
		];
	}

	/**
	 * Says why a merge was refused, for one of the ERROR constants or the error for a
	 * request that does not exist. Returns '' for any other code.
	 *
	 * @param string $code Error code.
	 */
	public static function message( string $code ): string {
		$messages = [
			VoteService::ERROR_NOT_FOUND   => __( 'This request does not exist.', 'feature-request-system' ),
			self::ERROR_ALREADY_MERGED     => __( 'This request was merged already. Merging cannot be undone.', 'feature-request-system' ),
			self::ERROR_INTO_ITSELF        => __( 'A request cannot be merged into itself.', 'feature-request-system' ),
			self::ERROR_INVALID_TARGET     => __( 'The request to merge into does not exist.', 'feature-request-system' ),
			self::ERROR_INTO_MERGED        => __( 'The request to merge into was merged into another itself. Merge into that one instead.', 'feature-request-system' ),
			self::ERROR_UNPUBLISHED_TARGET => __( 'Requests can only be merged into a published request.', 'feature-request-system' ),
			self::ERROR_OTHER_BOARD        => __( 'Requests can only be merged into a request on the same board.', 'feature-request-system' ),
		];

		return $messages[ $code ] ?? '';
	}

	/**
	 * Builds the error for a refused merge.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 */
	private static function error( string $code, int $status ): WP_Error {
		return new WP_Error( $code, self::message( $code ), [ 'status' => $status ] );
	}

	/**
	 * Whether a post is a request.
	 *
	 * @param mixed $post Post or null.
	 * @phpstan-assert-if-true WP_Post $post
	 */
	private static function is_request( $post ): bool {
		return $post instanceof WP_Post && RequestPostType::NAME === $post->post_type;
	}
}
