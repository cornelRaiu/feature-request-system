<?php
/**
 * WP-CLI commands.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Cli;

use FeatureRequestSystem\Voting\VoteCount;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance commands for Feature Request System.
 */
final class Commands {

	/**
	 * Top-level command name, as in `wp mwtfrs recount`.
	 */
	public const NAME = 'mwtfrs';

	/**
	 * Recounts every request's votes and fixes stored counts that are wrong or missing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mwtfrs recount
	 *
	 * @when after_wp_load
	 */
	public function recount(): void {
		$result = VoteCount::sync_all();

		WP_CLI::success(
			sprintf(
				'Checked %1$d %2$s and fixed %3$d %4$s.',
				$result['checked'],
				1 === $result['checked'] ? 'request' : 'requests',
				$result['fixed'],
				1 === $result['fixed'] ? 'vote count' : 'vote counts'
			)
		);
	}

	/**
	 * Creates sample requests with votes, to try the plugin or to test it with a lot of data.
	 *
	 * The requests are published on the default board, in English. Their votes come from
	 * made-up visitors, so no emails are sent and no user is involved.
	 *
	 * ## OPTIONS
	 *
	 * [--requests=<number>]
	 * : How many requests to create.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--votes=<number>]
	 * : How many votes to spread over them. A few requests get many, most get few.
	 * ---
	 * default: 400
	 * ---
	 *
	 * [--delete]
	 * : Delete the sample requests instead of creating more.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mwtfrs seed
	 *     wp mwtfrs seed --requests=1000 --votes=50000
	 *     wp mwtfrs seed --delete
	 *
	 * @when after_wp_load
	 *
	 * @param string[]             $args       Positional arguments. None are used.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function seed( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['delete'] ) ) {
			$deleted = SampleData::delete();

			WP_CLI::success( sprintf( 'Deleted %1$d sample %2$s.', $deleted, 1 === $deleted ? 'request' : 'requests' ) );
			return;
		}

		$requests = (int) ( $assoc_args['requests'] ?? 30 );
		$votes    = (int) ( $assoc_args['votes'] ?? 400 );

		$created = SampleData::create(
			$requests,
			$votes,
			static function ( int $done ) use ( $requests ): void {
				if ( 0 === $done % 100 && $done < $requests ) {
					WP_CLI::log( sprintf( '%1$d of %2$d requests created.', $done, $requests ) );
				}
			}
		);

		WP_CLI::success(
			sprintf(
				'Created %1$d sample %2$s with %3$d %4$s. Remove them with: wp %5$s seed --delete',
				$created['requests'],
				1 === $created['requests'] ? 'request' : 'requests',
				$created['votes'],
				1 === $created['votes'] ? 'vote' : 'votes',
				self::NAME
			)
		);
	}
}
