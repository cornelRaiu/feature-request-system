<?php
/**
 * Emails about merged requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Merging\MergedStatus;
use FeatureRequestSystem\Merging\MergeService;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Voting\VoteRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the people of a merged request where it went: everyone who followed it, only the
 * person who suggested it, or nobody, as the site or the request's board chose.
 *
 * Followers move to the other request whatever the choice. The email goes to each person
 * as a follower of that request, so it ends with the link that stops its emails, and a
 * person who does not follow it gets none. The person who merged is never emailed.
 */
final class MergeNotifier {

	/**
	 * Background hook that emails one batch of people. Receives the merged request's ID,
	 * the ID of the request it was merged into and the IDs of the users to email.
	 */
	public const HOOK = 'mwtfrs_send_merge_emails';

	/**
	 * Users emailed by one background run.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Adds the hooks that schedule the emails and send them.
	 */
	public static function add_hooks(): void {
		add_action( MergeService::ACTION_MERGED, [ self::class, 'announce' ], 10, 3 );
		add_action( self::HOOK, [ self::class, 'send_batch' ], 10, 3 );
	}

	/**
	 * Schedules the emails about a merge, one background run per batch of people.
	 *
	 * @param int   $request_id ID of the merged request.
	 * @param int   $target_id  ID of the request it was merged into.
	 * @param int[] $followers  IDs of the users who followed the merged request.
	 */
	public static function announce( int $request_id, int $target_id, array $followers ): void {
		if ( ! Subscriptions::enabled() ) {
			return;
		}

		$board = RequestTerms::term_of( $target_id, BoardTaxonomy::NAME );
		switch ( BoardSettings::merge_email( $board ? $board->term_id : 0 ) ) {
			case Settings::MERGE_EMAIL_FOLLOWERS:
				$users = $followers;
				break;
			case Settings::MERGE_EMAIL_SUBMITTER:
				$users = [ (int) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_ID, true ) ];
				break;
			default:
				$users = [];
		}

		// Whoever merged knows about it, and nobody is emailed twice.
		$users = array_values( array_diff( array_unique( array_filter( array_map( 'intval', $users ) ) ), [ get_current_user_id() ] ) );

		foreach ( array_chunk( $users, self::BATCH_SIZE ) as $batch ) {
			Queue::schedule( time(), self::HOOK, [ $request_id, $target_id, $batch ] );
		}
	}

	/**
	 * Emails one batch of people about a merge.
	 *
	 * Each is emailed as a follower of the request the merged one went to, read now, so
	 * nobody who stopped following it in between gets an email.
	 *
	 * @param int   $request_id ID of the merged request.
	 * @param int   $target_id  ID of the request it was merged into.
	 * @param int[] $users      IDs of the users to email.
	 */
	public static function send_batch( int $request_id, int $target_id, array $users ): void {
		$merged = get_post( $request_id );
		if ( ! $merged || MergedStatus::into( $request_id ) !== $target_id || 'publish' !== get_post_status( $target_id ) || ! Subscriptions::enabled() ) {
			return;
		}

		$merged_title = Mailer::plain( $merged->post_title );

		foreach ( array_map( 'intval', $users ) as $user_id ) {
			$subscription = SubscriptionRepository::find( $target_id, $user_id );
			if ( ! $subscription ) {
				continue;
			}

			$voted = VoteRepository::user_voted( $target_id, $user_id );

			Mailer::send(
				$subscription,
				'request-merged',
				static function ( WP_Post $request ) use ( $merged_title, $voted ): array {
					return [
						'subject' => sprintf(
							/* translators: 1: site name, 2: title of the merged request, 3: title of the request it was merged into. */
							__( '[%1$s] %2$s was merged into %3$s', 'feature-request-system' ),
							Mailer::plain( (string) get_bloginfo( 'name' ) ),
							$merged_title,
							Mailer::plain( $request->post_title )
						),
						'args'    => [
							'merged_title' => $merged_title,
							'voted'        => $voted,
						],
					];
				}
			);
		}
	}
}
