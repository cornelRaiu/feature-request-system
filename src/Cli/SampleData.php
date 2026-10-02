<?php
/**
 * Sample requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Cli;

use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Voting\VoteCount;

defined( 'ABSPATH' ) || exit;

/**
 * Creates sample requests with votes, for trying the plugin and for testing it with a
 * lot of data, and deletes them again. Used by `wp mwtfrs seed`.
 *
 * The requests are in English and written like real ones. Their votes come from made-up
 * visitors without an account, so no user is tied to them.
 */
final class SampleData {

	/**
	 * Post meta and term meta that mark what this class created, so it can be deleted again.
	 */
	public const META = '_mwtfrs_sample';

	/**
	 * Votes written per query.
	 */
	private const VOTES_PER_QUERY = 500;

	/**
	 * What the sample requests ask for.
	 */
	private const TOPICS = [
		'Dark mode',
		'Export to CSV',
		'Two-step login',
		'Slack notifications',
		'Weekly email digest',
		'Keyboard shortcuts',
		'Bulk editing',
		'Custom fields',
		'A public API',
		'Calendar view',
		'Offline mode',
		'Single sign-on',
		'Import from a spreadsheet',
		'Saved filters',
		'Mentions in comments',
		'An activity log',
		'Custom branding',
		'Webhooks',
		'Recurring tasks',
		'File attachments',
		'Guest access',
		'Time tracking',
		'Printable reports',
		'Drag and drop ordering',
		'An undo button',
	];

	/**
	 * Where the sample requests want it. The empty one leaves the topic as it is.
	 */
	private const PLACES = [ '', 'for reports', 'on mobile', 'for teams', 'in the dashboard', 'for invoices', 'for projects', 'in the editor' ];

	/**
	 * Descriptions of the sample requests.
	 */
	private const DETAILS = [
		'We do this by hand every week, and it takes about an hour each time.',
		'Our whole team keeps asking for this. It would save us a lot of clicks.',
		'I switched from another tool that had this, and it is the one thing I miss.',
		'This would help us a lot when we work with clients who are not on the same plan.',
		'It does not have to be fancy. A simple first version would already do.',
		'I can work around it with a browser extension, but it breaks with every update.',
	];

	/**
	 * Categories created for the sample requests on a site that has none.
	 */
	private const CATEGORIES = [ 'Appearance', 'Integrations', 'Reports', 'Mobile app' ];

	/**
	 * Creates published sample requests and spreads votes over them, most for the first ones.
	 *
	 * Most requests stay in the default status. The rest are spread over the other
	 * statuses. Each gets one category.
	 *
	 * @param int           $requests How many requests to create.
	 * @param int           $votes    How many votes to spread over them.
	 * @param callable|null $progress Called after each request with the number created so far.
	 * @phpstan-param (callable(int): void)|null $progress
	 * @return array{requests: int, votes: int} What was created.
	 */
	public static function create( int $requests, int $votes, ?callable $progress = null ): array {
		$statuses   = self::term_ids( StatusTaxonomy::NAME );
		$categories = self::categories();
		$shares     = self::vote_shares( $requests, $votes );
		$created    = 0;
		$voted      = 0;

		wp_defer_term_counting( true );

		for ( $index = 0; $index < $requests; $index++ ) {
			$request_id = wp_insert_post(
				[
					'post_type'     => RequestPostType::NAME,
					'post_status'   => 'publish',
					'post_title'    => self::title( $index ),
					'post_content'  => self::DETAILS[ $index % count( self::DETAILS ) ],
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - wp_rand( HOUR_IN_SECONDS, 180 * DAY_IN_SECONDS ) ),
				]
			);
			if ( ! $request_id ) {
				continue;
			}

			update_post_meta( $request_id, self::META, 1 );

			// Two requests in five move on from the default status, in turn to each of the others.
			if ( $statuses && in_array( $index % 5, [ 2, 4 ], true ) ) {
				wp_set_object_terms( $request_id, $statuses[ $index % count( $statuses ) ], StatusTaxonomy::NAME );
			}
			if ( $categories ) {
				wp_set_object_terms( $request_id, $categories[ $index % count( $categories ) ], CategoryTaxonomy::NAME );
			}

			$voted += self::add_votes( $request_id, $shares[ $index ] );
			VoteCount::sync( $request_id );

			++$created;
			if ( $progress ) {
				$progress( $created );
			}
		}

		wp_defer_term_counting( false );

		return [
			'requests' => $created,
			'votes'    => $voted,
		];
	}

	/**
	 * Deletes every sample request, with its votes and comments, and the categories that were created for them.
	 *
	 * @return int How many requests were deleted.
	 */
	public static function delete(): int {
		$deleted = 0;

		do {
			$ids = get_posts(
				[
					'post_type'      => RequestPostType::NAME,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A maintenance command.
				]
			);

			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
				++$deleted;
			}
		} while ( $ids );

		$categories = get_terms(
			[
				'taxonomy'   => CategoryTaxonomy::NAME,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A maintenance command.
			]
		);
		foreach ( is_array( $categories ) ? $categories : [] as $category ) {
			wp_delete_term( (int) $category, CategoryTaxonomy::NAME );
		}

		return $deleted;
	}

	/**
	 * Returns the title of the sample request at a position. Titles only repeat on very large runs.
	 *
	 * @param int $index Position, from 0.
	 */
	private static function title( int $index ): string {
		$topic = self::TOPICS[ $index % count( self::TOPICS ) ];
		$place = self::PLACES[ intdiv( $index, count( self::TOPICS ) ) % count( self::PLACES ) ];
		$round = intdiv( $index, count( self::TOPICS ) * count( self::PLACES ) );

		return trim( $topic . ' ' . $place ) . ( $round ? ' (' . ( $round + 1 ) . ')' : '' );
	}

	/**
	 * Returns the categories for the sample requests: the site's own, or new ones on a site that has none.
	 *
	 * @return int[]
	 */
	private static function categories(): array {
		$existing = self::term_ids( CategoryTaxonomy::NAME );
		if ( $existing ) {
			return $existing;
		}

		$created = [];
		foreach ( self::CATEGORIES as $name ) {
			$term = wp_insert_term( $name, CategoryTaxonomy::NAME );
			if ( ! is_wp_error( $term ) ) {
				update_term_meta( (int) $term['term_id'], self::META, 1 );
				$created[] = (int) $term['term_id'];
			}
		}

		return $created;
	}

	/**
	 * Returns the IDs of a taxonomy's terms.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	private static function term_ids( string $taxonomy ): array {
		$ids = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'term_id',
			]
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/**
	 * Splits a number of votes over the requests, so that a few get many and most get few, as on a real board.
	 *
	 * @param int $requests How many requests.
	 * @param int $votes    How many votes in all.
	 * @return int[] Votes per request, by position.
	 */
	private static function vote_shares( int $requests, int $votes ): array {
		if ( $requests < 1 ) {
			return [];
		}

		$weights = [];
		for ( $index = 0; $index < $requests; $index++ ) {
			$weights[] = 1 / ( ( $index + 1 ) ** 0.7 );
		}
		$total = array_sum( $weights );

		$shares = [];
		foreach ( $weights as $weight ) {
			$shares[] = (int) floor( $votes * $weight / $total );
		}

		// What rounding down left over goes to the first requests, one each.
		$left = $votes - array_sum( $shares );
		for ( $index = 0; $index < $left; $index++ ) {
			++$shares[ $index % $requests ];
		}

		return $shares;
	}

	/**
	 * Writes votes from made-up visitors for a request.
	 *
	 * @param int $request_id Request ID.
	 * @param int $votes      How many votes.
	 * @return int How many votes were written.
	 */
	private static function add_votes( int $request_id, int $votes ): int {
		global $wpdb;

		$written = 0;
		$now     = time();

		for ( $start = 0; $start < $votes; $start += self::VOTES_PER_QUERY ) {
			$count  = min( self::VOTES_PER_QUERY, $votes - $start );
			$values = [ Schema::table( Schema::VOTES ) ];

			for ( $number = $start; $number < $start + $count; $number++ ) {
				$values[] = $request_id;
				$values[] = hash( 'sha256', 'sample:' . $request_id . ':' . $number );
				$values[] = gmdate( 'Y-m-d H:i:s', $now - wp_rand( 0, 90 * DAY_IN_SECONDS ) );
			}

			$placeholders = implode( ',', array_fill( 0, $count, '(%d, %s, 0, 1, %s)' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table. Only placeholders are added to the query; their values follow.
			$written += (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (request_id, voter_key, user_id, vote, created_at) VALUES {$placeholders}", $values ) );
		}

		return $written;
	}
}
