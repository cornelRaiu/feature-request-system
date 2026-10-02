<?php
/**
 * Emails about status changes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusChange;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a request's followers when its status changes.
 *
 * The emails go out in the background, in batches, a few minutes after the change. The
 * wait lets a moderator correct a status picked by mistake before anyone hears of it, and
 * turns several changes in a row into one email. Each request remembers the one
 * announcement that is waiting: a later change replaces it, and the batches of the
 * replaced announcement then send nothing.
 */
final class StatusNotifier {

	/**
	 * Background hook that emails one batch of followers. Receives the request ID, the
	 * announcement's ID, and the first and last subscription ID of the batch.
	 */
	public const HOOK = 'mwtfrs_send_status_emails';

	/**
	 * Post meta with the announcement that is waiting or was sent last: its ID, the
	 * status the followers know (from), the new status (to), who made the change (actor)
	 * and when the emails are due.
	 */
	public const META = '_mwtfrs_status_notice';

	/**
	 * Filter to keep a status change from being announced. Receives true, the request ID,
	 * the old status and the new status. Return false to email nobody.
	 */
	public const FILTER_NOTIFY = 'mwtfrs_notify_status_change';

	/**
	 * Filter to change how many seconds pass between a status change and its emails.
	 */
	public const FILTER_DELAY = 'mwtfrs_status_email_delay';

	/**
	 * Filter to change how many followers one background run emails.
	 */
	public const FILTER_BATCH_SIZE = 'mwtfrs_status_email_batch_size';

	/**
	 * Seconds between a status change and its emails.
	 */
	public const DELAY = 300;

	/**
	 * Followers emailed by one background run.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Adds the hooks that schedule the emails and send them.
	 */
	public static function add_hooks(): void {
		add_action( StatusChange::ACTION, [ self::class, 'announce' ], 10, 3 );
		add_action( self::HOOK, [ self::class, 'send_batch' ], 10, 4 );
	}

	/**
	 * Seconds between a status change and its emails on this site.
	 */
	public static function delay(): int {
		return max( 0, (int) apply_filters( self::FILTER_DELAY, self::DELAY ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
	}

	/**
	 * Schedules the emails about a status change, one background run per batch of followers.
	 *
	 * @param int     $request_id Request ID.
	 * @param WP_Term $old_status Status before the change.
	 * @param WP_Term $new_status Status after the change.
	 */
	public static function announce( int $request_id, WP_Term $old_status, WP_Term $new_status ): void {
		// What the followers know is the status before the first change they were not yet told about.
		$waiting = self::waiting( $request_id );
		$known   = $waiting ? $waiting['from'] : $old_status->term_id;

		// This change replaces the announcement that was waiting.
		delete_post_meta( $request_id, self::META );

		if ( ! Subscriptions::enabled() || 'publish' !== get_post_status( $request_id ) || $known === $new_status->term_id ) {
			return;
		}

		if ( ! apply_filters( self::FILTER_NOTIFY, true, $request_id, $old_status, $new_status ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
			return;
		}

		$subscription_ids = SubscriptionRepository::ids( $request_id );
		if ( ! $subscription_ids ) {
			return;
		}

		$notice = [
			'id'    => wp_generate_uuid4(),
			'from'  => $known,
			'to'    => $new_status->term_id,
			'actor' => get_current_user_id(),
			'due'   => time() + self::delay(),
		];
		update_post_meta( $request_id, self::META, $notice );

		$batch_size = max( 1, (int) apply_filters( self::FILTER_BATCH_SIZE, self::BATCH_SIZE ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		// A batch is a range of subscription IDs, so people who follow after the change are not in any batch.
		foreach ( array_chunk( $subscription_ids, $batch_size ) as $batch ) {
			Queue::schedule( $notice['due'], self::HOOK, [ $request_id, $notice['id'], $batch[0], $batch[ count( $batch ) - 1 ] ] );
		}
	}

	/**
	 * Emails one batch of a request's followers about its new status.
	 *
	 * Followers are read now, not when the status changed, so nobody who stopped following
	 * in between gets an email.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $notice_id  ID of the announcement this batch belongs to.
	 * @param int    $first_id   First subscription ID of the batch.
	 * @param int    $last_id    Last subscription ID of the batch.
	 */
	public static function send_batch( int $request_id, string $notice_id, int $first_id, int $last_id ): void {
		$notice  = self::notice( $request_id );
		$request = get_post( $request_id );
		$status  = RequestTerms::term_of( $request_id, StatusTaxonomy::NAME );

		// Out of date: a later change replaced the announcement, or the request is no longer public.
		if ( ! $notice || $notice['id'] !== $notice_id || ! $status || $status->term_id !== $notice['to'] ) {
			return;
		}
		if ( ! $request || 'publish' !== $request->post_status || ! Subscriptions::enabled() ) {
			return;
		}

		$old      = get_term( $notice['from'], StatusTaxonomy::NAME );
		$old_name = $old instanceof WP_Term ? $old->name : '';

		foreach ( SubscriptionRepository::in_range( $request_id, $first_id, $last_id ) as $subscription ) {
			// Whoever changed the status knows about it.
			if ( $subscription['user_id'] === $notice['actor'] ) {
				continue;
			}

			Mailer::send(
				$subscription,
				'status-changed',
				static function ( WP_Post $request ) use ( $status, $old_name ): array {
					return [
						'subject' => sprintf(
							/* translators: 1: site name, 2: status name, such as Planned, 3: request title. */
							__( '[%1$s] Status changed to %2$s: %3$s', 'feature-request-system' ),
							Mailer::plain( (string) get_bloginfo( 'name' ) ),
							Mailer::plain( $status->name ),
							Mailer::plain( $request->post_title )
						),
						'args'    => [
							'status'     => Mailer::plain( $status->name ),
							'old_status' => Mailer::plain( $old_name ),
						],
					];
				}
			);
		}
	}

	/**
	 * Returns a request's announcement if its emails are not due yet, or null.
	 *
	 * @param int $request_id Request ID.
	 * @return array{id: string, from: int, to: int, actor: int, due: int}|null
	 */
	private static function waiting( int $request_id ): ?array {
		$notice = self::notice( $request_id );

		return $notice && $notice['due'] > time() ? $notice : null;
	}

	/**
	 * Returns the announcement stored with a request, or null when there is none.
	 *
	 * @param int $request_id Request ID.
	 * @return array{id: string, from: int, to: int, actor: int, due: int}|null
	 */
	private static function notice( int $request_id ): ?array {
		$notice = get_post_meta( $request_id, self::META, true );
		if ( ! is_array( $notice ) || ! isset( $notice['id'], $notice['from'], $notice['to'], $notice['actor'], $notice['due'] ) ) {
			return null;
		}

		return [
			'id'    => (string) $notice['id'],
			'from'  => (int) $notice['from'],
			'to'    => (int) $notice['to'],
			'actor' => (int) $notice['actor'],
			'due'   => (int) $notice['due'],
		];
	}
}
