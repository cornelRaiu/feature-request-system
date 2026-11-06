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
	 * The mwtfrs_can_vote filter refused a vote that the board would allow.
	 */
	public const ERROR_NOT_ALLOWED = 'mwtfrs_vote_not_allowed';

	/**
	 * The voter has given all the votes the board allows. The error data holds the limit.
	 */
	public const ERROR_LIMIT_REACHED = 'mwtfrs_vote_limit_reached';

	/**
	 * Filter that decides whether the current visitor may vote on a request. Receives
	 * whether the board's rules allow it, the request ID and the user ID, 0 for a visitor
	 * who is not logged in. It can refuse what the board allows and allow what it refuses,
	 * but not open a request that is not published or whose status is closed.
	 */
	public const FILTER_CAN_VOTE = 'mwtfrs_can_vote';

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

		if ( self::can_vote( $request_id, $rule ) ) {
			return true;
		}
		if ( BoardSettings::NOBODY === $rule ) {
			return new WP_Error( self::ERROR_DISABLED, __( 'Voting is turned off for this board.', 'feature-request-system' ), [ 'status' => 403 ] );
		}
		if ( BoardSettings::LOGGED_IN === $rule && ! is_user_logged_in() ) {
			return new WP_Error( self::ERROR_LOGIN_REQUIRED, __( 'Log in to vote on this request.', 'feature-request-system' ), [ 'status' => 401 ] );
		}

		return new WP_Error( self::ERROR_NOT_ALLOWED, __( 'You cannot vote on this request.', 'feature-request-system' ), [ 'status' => 403 ] );
	}

	/**
	 * Whether the current visitor may vote under a board's rule, as the mwtfrs_can_vote
	 * filter decides. The vote button is drawn from this too, so the page and the vote agree.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $rule       Voting rule of the request's board, a BoardSettings value.
	 */
	public static function can_vote( int $request_id, string $rule ): bool {
		$allowed = BoardSettings::ANYONE === $rule || ( BoardSettings::LOGGED_IN === $rule && is_user_logged_in() );

		return (bool) apply_filters( self::FILTER_CAN_VOTE, $allowed, $request_id, get_current_user_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
	}

	/**
	 * How many votes the current visitor has left on a request's board, or null when the
	 * board has no limit.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function votes_left( int $request_id ): ?int {
		$board = RequestTerms::term_of( $request_id, BoardTaxonomy::NAME );

		return $board ? VoteLimit::left( $board->term_id, VoterIdentity::key() ) : null;
	}

	/**
	 * Casts the current visitor's vote. Voting again for the same request changes nothing.
	 *
	 * @param int $request_id Request ID.
	 * @return array{votes: int, voted: bool, votes_left?: int}|WP_Error The new vote count and, on a board with a
	 *                                                                    vote limit, the votes left; or why the vote was refused.
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

		// The vote is counted first and judged after, so votes sent together cannot all pass on the same count.
		if ( $added && self::over_limit( $request_id, $voter ) ) {
			VoteRepository::remove( $request_id, $voter );

			return self::limit_reached( $request_id );
		}

		$votes = VoteCount::sync( $request_id );

		// Voting again is not a new vote, so it is not announced again.
		if ( $added ) {
			do_action( self::ACTION_CAST, $request_id, get_current_user_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
		}

		return self::with_votes_left(
			[
				'votes' => $votes,
				'voted' => true,
			],
			$request_id
		);
	}

	/**
	 * Removes the current visitor's vote. Retracting a vote that does not exist changes nothing.
	 *
	 * @param int $request_id Request ID.
	 * @return array{votes: int, voted: bool, votes_left?: int}|WP_Error The new vote count and, on a board with a
	 *                                                                    vote limit, the votes left; or why the change was refused.
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

		return self::with_votes_left(
			[
				'votes' => $votes,
				'voted' => false,
			],
			$request_id
		);
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
	 * Whether a vote just added takes its voter over the vote limit of the request's board.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $voter      Voter key.
	 */
	private static function over_limit( int $request_id, string $voter ): bool {
		$board = RequestTerms::term_of( $request_id, BoardTaxonomy::NAME );

		return $board && VoteLimit::exceeded( $board->term_id, $voter );
	}

	/**
	 * Builds the error for a voter who has given all the votes the board allows.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function limit_reached( int $request_id ): WP_Error {
		$board = RequestTerms::term_of( $request_id, BoardTaxonomy::NAME );

		return new WP_Error(
			self::ERROR_LIMIT_REACHED,
			__( 'You have used all your votes on this board. You get one back when you take a vote back, or when a request you voted for is closed.', 'feature-request-system' ),
			[
				'status' => 403,
				'limit'  => $board ? BoardSettings::vote_limit( $board->term_id ) : 0,
			]
		);
	}

	/**
	 * Adds how many votes the voter has left, on a board that limits them.
	 *
	 * @param array{votes: int, voted: bool} $result     Result of the vote.
	 * @param int                            $request_id Request ID.
	 * @return array{votes: int, voted: bool, votes_left?: int}
	 */
	private static function with_votes_left( array $result, int $request_id ): array {
		$left = self::votes_left( $request_id );
		if ( null !== $left ) {
			$result['votes_left'] = $left;
		}

		return $result;
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
