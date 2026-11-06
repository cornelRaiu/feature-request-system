<?php
/**
 * Trending requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Voting;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Notifications\Queue;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each request's number of recent votes in post meta, so the board can sort by
 * what is wanted now and not only by what collected votes over the years.
 *
 * A vote changes the number of its request at once. Votes also grow old without anyone
 * voting, so every request is recounted once an hour in the background.
 */
final class Trending {

	/**
	 * Post meta holding the number of votes of the last DAYS days. Every request has it from
	 * the moment it is created, so sorting by it never leaves a request out.
	 */
	public const META = '_mwtfrs_trending';

	/**
	 * Background hook that recounts every request, once an hour.
	 */
	public const HOOK = 'mwtfrs_recount_trending';

	/**
	 * Votes count as recent for this many days.
	 */
	public const DAYS = 30;

	/**
	 * Requests recounted in one step of the hourly run.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Adds the hooks that keep the numbers up to date.
	 */
	public static function add_hooks(): void {
		add_action( 'wp_after_insert_post', [ self::class, 'initialise' ], 10, 2 );
		add_action( VoteService::ACTION_CAST, [ self::class, 'sync' ] );
		add_action( VoteService::ACTION_RETRACTED, [ self::class, 'sync' ] );
		add_action( self::HOOK, [ self::class, 'sync_all' ] );
		add_action( 'action_scheduler_init', [ self::class, 'maybe_schedule' ] );
	}

	/**
	 * Gives a new request its number, 0, so lists sorted by it include the request.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Saved post.
	 */
	public static function initialise( int $post_id, WP_Post $post ): void {
		if ( RequestPostType::NAME === $post->post_type ) {
			add_post_meta( $post_id, self::META, 0, true );
		}
	}

	/**
	 * Recounts one request's recent votes and stores the number.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function sync( int $request_id ): void {
		update_post_meta( $request_id, self::META, VoteRepository::count_since( $request_id, self::since() ) );
	}

	/**
	 * Recounts every request, a batch at a time, and stores the numbers that changed.
	 */
	public static function sync_all(): void {
		global $wpdb;

		$since   = self::since();
		$last_id = 0;

		do {
			$ids = array_map(
				'intval',
				$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batches over every request in any status.
					$wpdb->prepare(
						'SELECT ID FROM %i WHERE post_type = %s AND ID > %d ORDER BY ID LIMIT %d',
						$wpdb->posts,
						RequestPostType::NAME,
						$last_id,
						self::BATCH_SIZE
					)
				)
			);

			// One query for the batch's counts, and one for what is stored.
			$counts = VoteRepository::counts_since( $ids, $since );
			update_meta_cache( 'post', $ids );

			foreach ( $ids as $id ) {
				$last_id = $id;

				$count  = $counts[ $id ] ?? 0;
				$stored = metadata_exists( 'post', $id, self::META ) ? (int) get_post_meta( $id, self::META, true ) : null;
				if ( $count !== $stored ) {
					update_post_meta( $id, self::META, $count );
				}
			}
		} while ( $ids );
	}

	/**
	 * Makes sure the hourly recount is scheduled. Runs when Action Scheduler is ready.
	 *
	 * Checking costs a query, so it happens where background work runs, in the admin, in
	 * WP-Cron and in WP-CLI, and not on every page a visitor opens.
	 */
	public static function maybe_schedule(): void {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedules the hourly recount, unless it is already scheduled.
	 */
	public static function schedule(): void {
		if ( ! as_has_scheduled_action( self::HOOK, [], Queue::GROUP ) ) {
			as_schedule_recurring_action( time(), HOUR_IN_SECONDS, self::HOOK, [], Queue::GROUP );
		}
	}

	/**
	 * Stops the hourly recount. Does nothing when Action Scheduler is not running.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			as_unschedule_all_actions( self::HOOK, [], Queue::GROUP );
		}
	}

	/**
	 * The moment from which votes count as recent, in UTC like the votes' dates.
	 */
	private static function since(): string {
		return gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS );
	}
}
