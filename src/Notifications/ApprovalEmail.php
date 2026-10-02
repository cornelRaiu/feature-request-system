<?php
/**
 * Email about an approved request.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use FeatureRequestSystem\Content\RequestPostType;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the user who suggested a request that it was approved.
 *
 * The email goes to the submitter as a follower of their own request, so it carries the
 * link that stops the emails, and a submitter who does not follow the request gets none.
 * Visitors who suggested a request without an account are not emailed.
 */
final class ApprovalEmail {

	/**
	 * Background hook that sends the email. Receives the request ID.
	 */
	public const HOOK = 'mwtfrs_send_approval_email';

	/**
	 * Adds the hooks that schedule the email and send it.
	 */
	public static function add_hooks(): void {
		add_action( 'pending_to_publish', [ self::class, 'schedule' ] );
		add_action( self::HOOK, [ self::class, 'send' ] );
	}

	/**
	 * Schedules the email when a request that waited for approval is published.
	 *
	 * @param WP_Post $post Post of any type that waited for approval and was published.
	 */
	public static function schedule( WP_Post $post ): void {
		// Only a request that a logged-in user suggested has a submitter.
		$submitter = self::submitter( $post->ID );

		// A moderator who approves their own request knows about it.
		if ( $submitter && get_current_user_id() !== $submitter && Subscriptions::enabled() && Subscriptions::is_following( $post->ID, $submitter ) ) {
			Queue::schedule( time(), self::HOOK, [ $post->ID ] );
		}
	}

	/**
	 * Sends the email, unless the request was unpublished or the submitter stopped following it meanwhile.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function send( int $request_id ): void {
		$request      = get_post( $request_id );
		$submitter    = self::submitter( $request_id );
		$subscription = $submitter ? SubscriptionRepository::find( $request_id, $submitter ) : null;

		if ( ! $request || 'publish' !== $request->post_status || ! $subscription || ! Subscriptions::enabled() ) {
			return;
		}

		Mailer::send(
			$subscription,
			'request-approved',
			static function ( WP_Post $request ): array {
				return [
					'subject' => sprintf(
						/* translators: 1: site name, 2: request title. */
						__( '[%1$s] Your feature request was approved: %2$s', 'feature-request-system' ),
						Mailer::plain( (string) get_bloginfo( 'name' ) ),
						Mailer::plain( $request->post_title )
					),
					'args'    => [],
				];
			}
		);
	}

	/**
	 * Returns the ID of the user who suggested a request, or 0.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function submitter( int $request_id ): int {
		return (int) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_ID, true );
	}
}
