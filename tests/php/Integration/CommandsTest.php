<?php
/**
 * WP-CLI command tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Cli\Commands;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Voting\VoteRepository;
use WP_CLI;

/**
 * What `wp mwtfrs seed` and `wp mwtfrs recount` do with their options, and what they print.
 */
final class CommandsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__ ) . '/Fakes/WP_CLI.php';
		WP_CLI::$lines = [];
	}

	public function test_seed_creates_30_requests_with_400_votes_unless_told_otherwise(): void {
		( new Commands() )->seed( [], [] );

		$this->assertSame( [ 'Success: Created 30 sample requests with 400 votes. Remove them with: wp mwtfrs seed --delete' ], WP_CLI::$lines );
		$this->assertCount( 30, $this->requests() );
		$this->assertSame( 400, $this->votes() );
	}

	public function test_seed_takes_the_numbers_it_is_given(): void {
		( new Commands() )->seed(
			[],
			[
				'requests' => '3',
				'votes'    => '7',
			]
		);
		( new Commands() )->seed(
			[],
			[
				'requests' => '1',
				'votes'    => '1',
			]
		);

		$this->assertSame(
			[
				'Success: Created 3 sample requests with 7 votes. Remove them with: wp mwtfrs seed --delete',
				'Success: Created 1 sample request with 1 vote. Remove them with: wp mwtfrs seed --delete',
			],
			WP_CLI::$lines
		);
		$this->assertCount( 4, $this->requests() );
		$this->assertSame( 8, $this->votes() );
	}

	public function test_seed_creates_nothing_for_a_number_below_one(): void {
		( new Commands() )->seed(
			[],
			[
				'requests' => '-5',
				'votes'    => '-10',
			]
		);

		$this->assertSame( [ 'Success: Created 0 sample requests with 0 votes. Remove them with: wp mwtfrs seed --delete' ], WP_CLI::$lines );
		$this->assertSame( [], $this->requests() );
	}

	public function test_seed_says_how_far_it_is_on_a_large_board(): void {
		( new Commands() )->seed(
			[],
			[
				'requests' => '201',
				'votes'    => '0',
			]
		);

		$this->assertSame(
			[
				'100 of 201 requests created.',
				'200 of 201 requests created.',
				'Success: Created 201 sample requests with 0 votes. Remove them with: wp mwtfrs seed --delete',
			],
			WP_CLI::$lines
		);
	}

	public function test_seed_with_delete_removes_the_sample_requests_and_creates_none(): void {
		$real = $this->create_request();
		( new Commands() )->seed( [], [ 'requests' => '3' ] );
		WP_CLI::$lines = [];

		( new Commands() )->seed( [], [ 'delete' => true ] );
		( new Commands() )->seed( [], [ 'requests' => '1' ] );
		( new Commands() )->seed( [], [ 'delete' => true ] );

		$this->assertSame( 'Success: Deleted 3 sample requests.', WP_CLI::$lines[0] );
		$this->assertSame( 'Success: Deleted 1 sample request.', WP_CLI::$lines[2] );
		$this->assertSame( [ $real ], $this->requests() );
	}

	public function test_recount_says_how_many_counts_it_checked_and_fixed(): void {
		$right = $this->create_request();
		$wrong = $this->create_request();
		VoteRepository::add( $wrong, hash( 'sha256', 'a voter' ), 0 );
		update_post_meta( $wrong, RequestPostType::META_VOTES, 99 );

		( new Commands() )->recount();
		( new Commands() )->recount();

		$this->assertSame(
			[
				'Success: Checked 2 requests and fixed 1 vote count.',
				'Success: Checked 2 requests and fixed 0 vote counts.',
			],
			WP_CLI::$lines
		);
		$this->assertSame( '1', get_post_meta( $wrong, RequestPostType::META_VOTES, true ) );
		$this->assertSame( '0', get_post_meta( $right, RequestPostType::META_VOTES, true ) );
	}

	/**
	 * Returns the IDs of all requests.
	 *
	 * @return int[]
	 */
	private function requests(): array {
		return get_posts(
			[
				'post_type'   => RequestPostType::NAME,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
	}

	/**
	 * Returns how many votes all requests have together.
	 */
	private function votes(): int {
		return (int) array_sum( array_map( [ VoteRepository::class, 'count' ], $this->requests() ) );
	}
}
