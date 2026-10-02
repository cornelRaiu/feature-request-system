<?php
/**
 * Sample data tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Cli\SampleData;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Notifications\StatusNotifier;
use FeatureRequestSystem\Voting\VoteRepository;

/**
 * What `wp mwtfrs seed` creates, and what `wp mwtfrs seed --delete` takes away again.
 */
final class SampleDataTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_it_creates_published_requests_with_the_votes_spread_over_them(): void {
		global $wpdb;

		$ticks = [];

		$created = SampleData::create(
			12,
			100,
			static function ( int $done ) use ( &$ticks ): void {
				$ticks[] = $done;
			}
		);

		$requests = get_posts(
			[
				'post_type'   => RequestPostType::NAME,
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			]
		);
		$counts   = [];
		foreach ( $requests as $request ) {
			$counts[] = VoteRepository::count( $request->ID );
			$this->assertSame( 'publish', $request->post_status );
			$this->assertSame( (string) end( $counts ), get_post_meta( $request->ID, RequestPostType::META_VOTES, true ), 'The stored count matches the votes.' );
			$this->assertCount( 1, $this->term_ids( $request->ID, StatusTaxonomy::NAME ) );
			$this->assertCount( 1, $this->term_ids( $request->ID, CategoryTaxonomy::NAME ) );
		}

		$this->assertSame( [ 'requests' => 12, 'votes' => 100 ], $created );
		$this->assertCount( 12, $requests );
		$this->assertSame( range( 1, 12 ), $ticks );
		$this->assertSame( 100, array_sum( $counts ) );
		$this->assertSame( max( $counts ), $counts[0], 'The first request gets the most votes.' );
		$this->assertGreaterThan( 2 * $counts[11], $counts[0], 'A few requests get many votes and most get few, as on a real board.' );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id <> 0', Schema::table( Schema::VOTES ) ) ), 'No vote is tied to a user.' );
		$this->assertCount( 12, array_unique( wp_list_pluck( $requests, 'post_title' ) ), 'Every request has its own title.' );
		$this->assertSame( 'Dark mode', $requests[0]->post_title );
	}

	public function test_titles_stay_different_on_a_board_with_more_requests_than_topics(): void {
		SampleData::create( 30, 0 );

		$titles = wp_list_pluck(
			get_posts(
				[
					'post_type'   => RequestPostType::NAME,
					'numberposts' => -1,
					'orderby'     => 'ID',
					'order'       => 'ASC',
				]
			),
			'post_title'
		);

		$this->assertCount( 30, array_unique( $titles ) );
		$this->assertSame( 'Dark mode', $titles[0] );
		$this->assertSame( 'Dark mode for reports', $titles[25], 'After the last topic, the first comes again with a place.' );
	}

	public function test_most_requests_stay_open_and_the_others_use_the_other_statuses(): void {
		SampleData::create( 30, 0 );

		$per_status = [];
		foreach ( get_terms( [ 'taxonomy' => StatusTaxonomy::NAME, 'hide_empty' => false ] ) as $status ) {
			$per_status[ $status->slug ] = $status->count;
		}

		$this->assertGreaterThanOrEqual( 18, $per_status['open'] );
		$this->assertSame( 30, array_sum( $per_status ) );
		$this->assertGreaterThanOrEqual( 4, count( array_filter( $per_status ) ), 'Several statuses are in use.' );
	}

	public function test_a_site_without_categories_gets_a_few_and_a_site_with_categories_keeps_its_own(): void {
		SampleData::create( 3, 0 );
		$this->assertSame( [ 'Appearance', 'Integrations', 'Mobile app', 'Reports' ], $this->category_names() );

		SampleData::delete();
		$own = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Billing',
			]
		);
		SampleData::create( 3, 0 );

		$this->assertSame( [ 'Billing' ], $this->category_names() );
		$this->assertSame( 3, get_term( $own )->count );
	}

	public function test_seeding_sends_no_emails_and_schedules_none(): void {
		SampleData::create( 10, 20 );

		$this->assertSame( [], $this->emailed() );
		$this->assertSame( [], $this->waiting_actions( StatusNotifier::HOOK ) );
	}

	public function test_deleting_takes_away_the_sample_requests_and_nothing_else(): void {
		$real     = $this->create_request( [ 'post_title' => 'A real request' ] );
		$category = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		VoteRepository::add( $real, hash( 'sha256', 'real voter' ), 0 );
		SampleData::create( 5, 30 );
		$sample = get_posts(
			[
				'post_type'   => RequestPostType::NAME,
				'numberposts' => 1,
				'exclude'     => [ $real ],
				'fields'      => 'ids',
			]
		)[0];

		$deleted = SampleData::delete();

		$this->assertSame( 5, $deleted );
		$this->assertSame( [ $real ], get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ] ) );
		$this->assertSame( 1, VoteRepository::count( $real ) );
		$this->assertSame( 0, VoteRepository::count( $sample ), 'The votes go with the requests.' );
		$this->assertNotNull( get_term( $category ) );
		$this->assertSame( 0, SampleData::delete(), 'Safe to run again.' );
	}

	/**
	 * Requests are deleted 100 at a time.
	 */
	public function test_deleting_takes_away_more_sample_requests_than_one_round_holds(): void {
		SampleData::create( 105, 0 );

		$this->assertSame( 105, SampleData::delete() );
		$this->assertSame( [], get_posts( [ 'post_type' => RequestPostType::NAME, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ] ) );
	}

	/**
	 * Votes are written 500 at a time.
	 */
	public function test_a_request_can_get_more_votes_than_one_query_writes(): void {
		$created = SampleData::create( 1, 1200 );
		$request = get_posts(
			[
				'post_type'   => RequestPostType::NAME,
				'numberposts' => 1,
				'fields'      => 'ids',
			]
		)[0];

		$this->assertSame( [ 'requests' => 1, 'votes' => 1200 ], $created );
		$this->assertSame( 1200, VoteRepository::count( $request ) );
		$this->assertSame( '1200', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	public function test_nothing_is_created_when_asked_for_nothing(): void {
		$this->assertSame( [ 'requests' => 0, 'votes' => 0 ], SampleData::create( 0, 50 ) );
	}

	/**
	 * Returns the names of all categories, alphabetically.
	 *
	 * @return string[]
	 */
	private function category_names(): array {
		return array_values(
			wp_list_pluck(
				get_terms(
					[
						'taxonomy'   => CategoryTaxonomy::NAME,
						'hide_empty' => false,
						'orderby'    => 'name',
					]
				),
				'name'
			)
		);
	}
}
