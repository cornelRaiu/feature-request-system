<?php
/**
 * Casting and retracting votes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Voting;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Security\RateLimiter;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The rules for voting. Every way of voting goes through this class, so the rules hold
 * for the REST API, forms and any code that calls it.
 */
final class VoteService {

	/**
	 * The request does not exist or is not published.
	 */
	public const ERROR_NOT_FOUND = 'mwtfrs_request_not_found';

	/**
	 * The request's status is closed, so its votes are frozen.
	 */
	public const ERROR_CLOSED = 'mwtfrs_voting_closed';

	/**
	 * Voting is turned off for the request's board.
	 */
	public const ERROR_DISABLED = 'mwtfrs_voting_disabled';

	/**
	 * The board only accepts votes from logged-in users.
	 */
	public const ERROR_LOGIN_REQUIRED = 'mwtfrs_login_required';

	/**
	 * The visitor voted too often. The error data holds retry_after in seconds.
	 */
	public const ERROR_RATE_LIMITED = 'mwtfrs_rate_limited';

	/**
	 * Action fired after a vote is added. Receives the request ID and the voter's user ID, 0 for a guest.
	 */
	public const ACTION_CAST = 'mwtfrs_vote_cast';

	/**
	 * Action fired after a vote is taken back. Receives the request ID and the voter's user ID, 0 for a guest.
	 */
	public const ACTION_RETRACTED = 'mwtfrs_vote_retracted';

	/**
	 * Adds the hooks that keep votes consistent with requests.
	 */
	public static function add_hooks(): void {
		add_action( 'deleted_post', [ self::class, 'delete_request_votes' ], 10, 2 );
	}

	/**
	 * Checks whether the current visitor may vote on a request right now.
	 *
	 * @param int $request_id Request ID.
	 * @return true|WP_Error
	 */
	public static function check( int $request_id ) {
		$request = get_post( $request_id );
		if ( ! $request || RequestPostType::NAME !== $request->post_type || 'publish' !== $request->post_status || '' !== $request->post_password ) {
			return new WP_Error( self::ERROR_NOT_FOUND, __( 'This request does not exist or is not open for voting.', 'feature-request-system' ), [ 'status' => 404 ] );
		}

		$status = RequestTerms::term_of( $request_id, StatusTaxonomy::NAME );
		if ( $status && StatusTaxonomy::is_closed( $status ) ) {
			return new WP_Error(
				self::ERROR_CLOSED,
				/* translators: %s: status name, such as Shipped. */
				sprintf( __( 'Voting is closed because this request is %s.', 'feature-request-system' ), $status->name ),
				[ 'status' => 403 ]
			);
		}

		$board = RequestTerms::term_of( $request_id, BoardTaxonomy::NAME );
		$rule  = BoardSettings::voting( $board ? $board->term_id : 0 );

		if ( BoardSettings::NOBODY === $rule ) {
			return new WP_Error( self::ERROR_DISABLED, __( 'Voting is turned off for this board.', 'feature-request-system' ), [ 'status' => 403 ] );
		}
		if ( BoardSettings::LOGGED_IN === $rule && ! is_user_logged_in() ) {
			return new WP_Error( self::ERROR_LOGIN_REQUIRED, __( 'Log in to vote on this request.', 'feature-request-system' ), [ 'status' => 401 ] );
		}

		return true;
	}

	/**
	 * Casts the current visitor's vote. Voting again for the same request changes nothing.
	 *
	 * @param int $request_id Request ID.
	 * @return array{votes: int, voted: bool}|WP_Error The new vote count, or why the vote was refused.
	 */
	public static function cast( int $request_id ) {
		$allowed = self::check( $request_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$voter = VoterIdentity::key( true );
		$wait  = RateLimiter::attempt( 'vote', $voter );
		if ( $wait ) {
			return self::rate_limited( $wait );
		}

		$added = VoteRepository::add( $request_id, $voter, get_current_user_id() );
		$votes = VoteCount::sync( $request_id );

		// Voting again is not a new vote, so it is not announced again.
		if ( $added ) {
			do_action( self::ACTION_CAST, $request_id, get_current_user_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
		}

		return [
			'votes' => $votes,
			'voted' => true,
		];
	}

	/**
	 * Removes the current visitor's vote. Retracting a vote that does not exist changes nothing.
	 *
	 * @param int $request_id Request ID.
	 * @return array{votes: int, voted: bool}|WP_Error The new vote count, or why the change was refused.
	 */
	public static function retract( int $request_id ) {
		$allowed = self::check( $request_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$voter = VoterIdentity::key();
		if ( '' === $voter ) {
			// A guest without a voter cookie has no vote to remove.
			return [
				'votes' => VoteCount::get( $request_id ),
				'voted' => false,
			];
		}

		$wait = RateLimiter::attempt( 'vote', $voter );
		if ( $wait ) {
			return self::rate_limited( $wait );
		}

		$removed = VoteRepository::remove( $request_id, $voter );
		$votes   = VoteCount::sync( $request_id );

		if ( $removed ) {
			do_action( self::ACTION_RETRACTED, $request_id, get_current_user_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
		}

		return [
			'votes' => $votes,
			'voted' => false,
		];
	}

	/**
	 * Deletes a request's votes when the request is permanently deleted.
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post    Deleted post.
	 */
	public static function delete_request_votes( int $post_id, $post ): void {
		if ( $post instanceof WP_Post && RequestPostType::NAME === $post->post_type ) {
			VoteRepository::remove_all( $post_id );
		}
	}

	/**
	 * Builds the error for a visitor who is over the vote limit.
	 *
	 * @param int $wait Seconds until the limit resets.
	 */
	private static function rate_limited( int $wait ): WP_Error {
		$minutes = (int) ceil( $wait / MINUTE_IN_SECONDS );

		return new WP_Error(
			self::ERROR_RATE_LIMITED,
			sprintf(
				/* translators: %d: number of minutes. */
				_n( 'Too many votes in a short time. Try again in %d minute.', 'Too many votes in a short time. Try again in %d minutes.', $minutes, 'feature-request-system' ),
				$minutes
			),
			[
				'status'      => 429,
				'retry_after' => $wait,
			]
		);
	}
}
