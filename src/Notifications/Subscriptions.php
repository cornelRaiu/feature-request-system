<?php
/**
 * Following requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Voting\VoteService;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The rules for following a request. A follower gets an email when the request's status
 * changes.
 *
 * Only logged-in users can follow, because their email address is known to be theirs.
 * A user follows a request by choosing to, by voting for it or by suggesting it. The
 * last two happen by themselves, unless the site or the user turned that off.
 */
final class Subscriptions {

	/**
	 * User meta that holds "0" for a user who turned off following by itself. Also the
	 * name of that choice's field on the profile screen.
	 */
	public const META_AUTO_FOLLOW = 'mwtfrs_auto_follow';

	/**
	 * Only logged-in users can follow a request.
	 */
	public const ERROR_LOGIN_REQUIRED = 'mwtfrs_follow_login_required';

	/**
	 * The site does not email followers, so there is nothing to follow.
	 */
	public const ERROR_DISABLED = 'mwtfrs_following_disabled';

	/**
	 * Adds the hooks that make voters and submitters follow, and that keep subscriptions
	 * consistent with requests and users.
	 */
	public static function add_hooks(): void {
		add_action( VoteService::ACTION_CAST, [ self::class, 'follow_voted' ], 10, 2 );
		add_action( VoteService::ACTION_RETRACTED, [ self::class, 'unfollow_unvoted' ], 10, 2 );
		add_action( SubmissionService::ACTION_SUBMITTED, [ self::class, 'follow_submitted' ] );
		add_action( 'deleted_post', [ self::class, 'delete_request_subscriptions' ], 10, 2 );
		add_action( 'deleted_user', [ self::class, 'delete_user_subscriptions' ] );
	}

	/**
	 * Whether the site lets users follow requests and emails them.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( Settings::NOTIFY_FOLLOWERS );
	}

	/**
	 * Whether a user follows a request.
	 *
	 * @param int $request_id Request ID.
	 * @param int $user_id    User ID.
	 */
	public static function is_following( int $request_id, int $user_id ): bool {
		return $user_id > 0 && null !== SubscriptionRepository::find( $request_id, $user_id );
	}

	/**
	 * Makes the current user follow a request. Following again changes nothing.
	 *
	 * @param int $request_id Request ID.
	 * @return array{following: bool}|WP_Error
	 */
	public static function follow( int $request_id ) {
		if ( ! is_user_logged_in() ) {
			return self::login_required();
		}
		if ( ! self::enabled() ) {
			return new WP_Error( self::ERROR_DISABLED, __( 'This site does not send emails about requests.', 'feature-request-system' ), [ 'status' => 403 ] );
		}

		$request = get_post( $request_id );
		if ( ! $request || RequestPostType::NAME !== $request->post_type || 'publish' !== $request->post_status || '' !== $request->post_password ) {
			return new WP_Error( VoteService::ERROR_NOT_FOUND, __( 'This request does not exist or cannot be followed.', 'feature-request-system' ), [ 'status' => 404 ] );
		}

		SubscriptionRepository::add( $request_id, get_current_user_id(), SubscriptionRepository::SOURCE_FOLLOW );

		return [ 'following' => true ];
	}

	/**
	 * Makes the current user stop following a request, whatever made them follow it.
	 *
	 * A user can always stop, also when the request is no longer public.
	 *
	 * @param int $request_id Request ID.
	 * @return array{following: bool}|WP_Error
	 */
	public static function unfollow( int $request_id ) {
		if ( ! is_user_logged_in() ) {
			return self::login_required();
		}

		SubscriptionRepository::remove( $request_id, get_current_user_id() );

		return [ 'following' => false ];
	}

	/**
	 * Whether a user wants to follow the requests they vote for or suggest. That is so
	 * until the user turns it off.
	 *
	 * @param int $user_id User ID.
	 */
	public static function wants_automatic( int $user_id ): bool {
		return '0' !== get_user_meta( $user_id, self::META_AUTO_FOLLOW, true );
	}

	/**
	 * Records whether a user wants to follow the requests they vote for or suggest.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $on      Whether the user wants that.
	 */
	public static function set_automatic( int $user_id, bool $on ): void {
		if ( $on ) {
			delete_user_meta( $user_id, self::META_AUTO_FOLLOW );
		} else {
			update_user_meta( $user_id, self::META_AUTO_FOLLOW, '0' );
		}
	}

	/**
	 * Makes a user follow the request they just voted for.
	 *
	 * @param int $request_id Request ID.
	 * @param int $user_id    Voter's user ID, or 0 for a guest.
	 */
	public static function follow_voted( int $request_id, int $user_id ): void {
		if ( $user_id && Settings::get( Settings::SUBSCRIBE_VOTERS ) && self::is_automatic_for( $user_id ) ) {
			SubscriptionRepository::add( $request_id, $user_id, SubscriptionRepository::SOURCE_VOTE );
		}
	}

	/**
	 * Makes a user who took back their vote stop following the request, if the vote was
	 * the only reason they followed it.
	 *
	 * @param int $request_id Request ID.
	 * @param int $user_id    Voter's user ID, or 0 for a guest.
	 */
	public static function unfollow_unvoted( int $request_id, int $user_id ): void {
		if ( $user_id ) {
			SubscriptionRepository::remove( $request_id, $user_id, SubscriptionRepository::SOURCE_VOTE );
		}
	}

	/**
	 * Makes a logged-in user follow the request they just suggested.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function follow_submitted( int $request_id ): void {
		$user_id = (int) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_ID, true );

		if ( $user_id && self::is_automatic_for( $user_id ) ) {
			SubscriptionRepository::add( $request_id, $user_id, SubscriptionRepository::SOURCE_AUTHOR );
		}
	}

	/**
	 * Deletes a request's subscriptions when the request is permanently deleted.
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post    Deleted post.
	 */
	public static function delete_request_subscriptions( int $post_id, $post ): void {
		if ( $post instanceof WP_Post && RequestPostType::NAME === $post->post_type ) {
			SubscriptionRepository::remove_request( $post_id );
		}
	}

	/**
	 * Deletes a user's subscriptions when the user is deleted.
	 *
	 * @param int $user_id Deleted user ID.
	 */
	public static function delete_user_subscriptions( int $user_id ): void {
		SubscriptionRepository::remove_user( $user_id );
	}

	/**
	 * Whether a user can come to follow a request without choosing to: the site emails
	 * followers, and the user did not turn this off.
	 *
	 * @param int $user_id User ID.
	 */
	private static function is_automatic_for( int $user_id ): bool {
		return self::enabled() && self::wants_automatic( $user_id );
	}

	/**
	 * Builds the error for a visitor who is not logged in.
	 */
	private static function login_required(): WP_Error {
		return new WP_Error( self::ERROR_LOGIN_REQUIRED, __( 'Log in to follow this request.', 'feature-request-system' ), [ 'status' => 401 ] );
	}
}
