<?php
/**
 * Trending tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Cli\SampleData;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\BoardQuery;
use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Notifications\Queue;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Voting\Trending;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteRepository;
use WP_REST_Request;

/**
 * Each request counts its votes of the last 30 days, on every vote and once an hour, and
 * the board, the list and the shortcode sort by that count.
 */
final class TrendingTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
	}

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	public function test_a_new_request_starts_at_zero_so_sorting_never_leaves_it_out(): void {
		$request = $this->create_request();

		$this->assertTrue( metadata_exists( 'post', $request, Trending::META ) );
		$this->assertSame( '0', get_post_meta( $request, Trending::META, true ) );
	}

	public function test_a_vote_recounts_only_the_votes_of_the_last_30_days(): void {
		$request = $this->create_request();
		$this->add_vote( $request, 45 );
		$this->add_vote( $request, 31 );
		$this->add_vote( $request, 29 );

		wp_set_current_user( self::factory()->user->create() );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/requests/' . $request . '/vote' ) );

		$this->assertSame( 4, $response->get_data()['votes'] );
		$this->assertSame( '2', get_post_meta( $request, Trending::META, true ), 'The vote just cast and the one from 29 days ago.' );

		rest_do_request( new WP_REST_Request( 'DELETE', '/' . RestApi::NAMESPACE . '/requests/' . $request . '/vote' ) );
		$this->assertSame( '1', get_post_meta( $request, Trending::META, true ), 'Taking the vote back recounts too.' );
	}

	public function test_the_hourly_recount_lets_old_votes_drop_out_and_fixes_every_request(): void {
		$aging   = $this->create_request();
		$missing = $this->create_request();
		$this->add_vote( $aging, 10 );
		$this->add_vote( $aging, 2 );
		Trending::sync( $aging );
		$this->assertSame( '2', get_post_meta( $aging, Trending::META, true ) );
		$this->add_vote( $missing, 1 );
		delete_post_meta( $missing, Trending::META );

		// Three weeks pass without a vote.
		$this->age_votes( 21 );
		Trending::schedule();
		$this->assertSame( 1, $this->run_waiting_actions( Trending::HOOK ) );

		$this->assertSame( '1', get_post_meta( $aging, Trending::META, true ), 'The vote now 31 days old no longer counts.' );
		$this->assertSame( '1', get_post_meta( $missing, Trending::META, true ), 'A request without the number gets it.' );
	}

	public function test_the_hourly_recount_is_scheduled_once_and_repeats_every_hour(): void {
		as_unschedule_all_actions( Trending::HOOK, [], Queue::GROUP );

		set_current_screen( 'dashboard' );
		Trending::maybe_schedule();
		Trending::maybe_schedule();
		set_current_screen( 'front' );

		$actions = $this->waiting_actions( Trending::HOOK );
		$this->assertCount( 1, $actions );
		$action = reset( $actions );
		$this->assertSame( Queue::GROUP, $action->get_group() );
		$this->assertSame( HOUR_IN_SECONDS, (int) $action->get_schedule()->get_recurrence() );

		Installer::deactivate();
		$this->assertSame( [], $this->waiting_actions( Trending::HOOK ), 'Deactivating stops it.' );
	}

	public function test_page_views_of_visitors_do_not_check_the_schedule(): void {
		as_unschedule_all_actions( Trending::HOOK, [], Queue::GROUP );

		Trending::maybe_schedule();

		$this->assertSame( [], $this->waiting_actions( Trending::HOOK ) );
	}

	public function test_installing_gives_older_requests_their_number(): void {
		$request = $this->create_request();
		$this->add_vote( $request, 3 );
		delete_post_meta( $request, Trending::META );

		Installer::install();

		$this->assertSame( '1', get_post_meta( $request, Trending::META, true ) );
	}

	public function test_the_board_sorts_by_trending_then_by_votes(): void {
		$this->request_with_votes( 'Old favourite', [ 60, 50, 40, 35 ] );
		$this->request_with_votes( 'Rising', [ 1, 2 ] );
		$this->request_with_votes( 'Quiet', [ 1 ] );
		$this->request_with_votes( 'Forgotten', [ 90 ] );
		$this->request_with_votes( 'Brand new', [] );

		$_GET[ BoardQuery::PARAM_SORT ] = BoardQuery::SORT_TRENDING;
		$html                           = $this->render_board();

		$this->assertTitlesInOrder( [ 'Rising', 'Quiet', 'Old favourite', 'Forgotten', 'Brand new' ], $html );
		$this->assertMatchesRegularExpression( '/<option value="trending"\s+selected=\'selected\'>Trending<\/option>/', $html );

		unset( $_GET[ BoardQuery::PARAM_SORT ] );
		$this->assertTitlesInOrder( [ 'Old favourite', 'Rising', 'Quiet' ], $this->render_board(), 'Most votes stays the default.' );
	}

	public function test_the_list_block_and_shortcode_take_trending_as_their_order(): void {
		$this->request_with_votes( 'Old favourite', [ 60, 50, 40 ] );
		$this->request_with_votes( 'Rising', [ 1, 2 ] );

		$block = render_block(
			[
				'blockName'    => Board::BLOCK,
				'attrs'        => [
					'compact' => true,
					'sort'    => BoardQuery::SORT_TRENDING,
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$this->assertTitlesInOrder( [ 'Rising', 'Old favourite' ], $block );

		$this->assertTitlesInOrder( [ 'Rising', 'Old favourite' ], do_shortcode( '[' . Blocks::LIST_SHORTCODE . ' sort="trending"]' ) );
		$this->assertTitlesInOrder( [ 'Old favourite', 'Rising' ], do_shortcode( '[' . Blocks::LIST_SHORTCODE . ' sort="unknown"]' ), 'An unknown order is the default.' );
	}

	public function test_sample_data_gets_its_trending_numbers(): void {
		$created = SampleData::create( 2, 10 );

		$this->assertSame( 2, $created['requests'] );
		foreach ( get_posts( [ 'post_type' => RequestPostType::NAME, 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
			$recent = VoteRepository::count_since( $id, gmdate( 'Y-m-d H:i:s', time() - Trending::DAYS * DAY_IN_SECONDS ) );
			$this->assertSame( (string) $recent, get_post_meta( $id, Trending::META, true ), 'Sample votes are spread over 90 days.' );
		}
	}

	/**
	 * Adds a vote cast some days ago.
	 *
	 * @param int $request_id Request ID.
	 * @param int $days_ago   Age of the vote in days.
	 */
	private function add_vote( int $request_id, int $days_ago ): void {
		global $wpdb;

		$key = hash( 'sha256', 'test:' . wp_generate_password( 20, false ) );
		VoteRepository::add( $request_id, $key, 0 );
		$wpdb->update(
			Schema::table( Schema::VOTES ),
			[ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ) ],
			[ 'voter_key' => $key ]
		);
		VoteCount::sync( $request_id );
	}

	/**
	 * Makes every vote older by some days, as if time passed.
	 *
	 * @param int $days Days to add to every vote's age.
	 */
	private function age_votes( int $days ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = created_at - INTERVAL %d DAY', Schema::table( Schema::VOTES ), $days ) );
	}

	/**
	 * Creates a published request with votes of the given ages, and its counts.
	 *
	 * @param string $title Request title.
	 * @param int[]  $ages  Age of each vote in days.
	 */
	private function request_with_votes( string $title, array $ages ): int {
		$request = $this->create_request( [ 'post_title' => $title ] );
		foreach ( $ages as $age ) {
			$this->add_vote( $request, $age );
		}
		Trending::sync( $request );

		return $request;
	}

	/**
	 * Renders the board block of the default board.
	 */
	private function render_board(): string {
		return render_block(
			[
				'blockName'    => Board::BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Asserts that titles appear in the given order.
	 *
	 * @param string[] $titles  Titles, first to last.
	 * @param string   $html    HTML of the block.
	 * @param string   $message Message on failure.
	 */
	private function assertTitlesInOrder( array $titles, string $html, string $message = '' ): void {
		$positions = array_map(
			static function ( string $title ) use ( $html ) {
				return strpos( $html, '>' . $title . '</a>' );
			},
			$titles
		);

		$this->assertNotContains( false, $positions, 'Every title is listed. ' . $message );
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions, $message );
	}
}
