<?php
/**
 * WP-CLI command tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Cli\Commands;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Voting\Trending;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Voting\VoteRepository;
use RuntimeException;
use WP_CLI;

/**
 * What `wp mwtfrs seed`, `wp mwtfrs recount` and `wp mwtfrs export` do with their options, and what they print.
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
		update_post_meta( $wrong, Trending::META, 99 );

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
		$this->assertSame( '1', get_post_meta( $wrong, Trending::META, true ), 'The trending number is recounted too.' );
	}

	public function test_export_prints_every_request_with_who_submitted_it(): void {
		$public  = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$private = $this->create_request(
			[
				'post_title'  => 'Internal tool',
				'post_status' => 'private',
			]
		);
		update_post_meta( $public, RequestPostType::META_SUBMITTER_NAME, 'Gina Guest' );
		update_post_meta( $public, RequestPostType::META_SUBMITTER_EMAIL, 'gina@example.com' );

		$csv = $this->export( [] );

		$this->assertStringStartsWith( "ID,Title,Status,Board,Categories,Votes,Followers,Date,Link,Submitter,Submitter email\r\n", $csv, 'No byte order mark in a terminal.' );
		$this->assertStringContainsString( "\r\n{$public},Dark mode,Open,", $csv );
		$this->assertStringContainsString( ',Gina Guest,gina@example.com' . "\r\n", $csv );
		$this->assertStringContainsString( "\r\n{$private},Internal tool,", $csv, 'Without --user, every request.' );
	}

	public function test_export_takes_the_filters_of_the_requests_list(): void {
		$planned = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$open    = $this->create_request( [ 'post_title' => 'Light mode' ] );
		$pending = $this->create_request(
			[
				'post_title'  => 'Dim mode',
				'post_status' => 'pending',
			]
		);
		wp_set_object_terms( $planned, $this->status( 'planned' ), StatusTaxonomy::NAME );
		$board = get_term( $this->default_board() )->slug;

		$this->assertSame( [ $planned ], $this->ids( $this->export( [ 'status' => 'planned' ] ) ) );
		$this->assertSame( [ $open ], $this->ids( $this->export( [ 'search' => 'light' ] ) ) );
		$this->assertSame( [ $pending ], $this->ids( $this->export( [ 'post_status' => 'pending' ] ) ) );
		$this->assertCount( 3, $this->ids( $this->export( [ 'board' => $board ] ) ) );
		$this->assertSame( [], $this->ids( $this->export( [ 'category' => 'no-such-category' ] ) ) );

		try {
			$this->export( [ 'post_status' => 'nonsense' ] );
			$this->fail( 'An unknown post status was taken for every status.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Error: There is no post status "nonsense".', WP_CLI::$lines[0] );
		}
	}

	public function test_export_with_voters_lists_the_votes_and_with_a_user_keeps_to_their_rights(): void {
		$request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$vera    = self::factory()->user->create(
			[
				'display_name' => 'Vera Voter',
				'user_email'   => 'vera@example.com',
			]
		);
		VoteRepository::add( $request, hash( 'sha256', 'u:' . $vera ), $vera );
		VoteRepository::add( $request, hash( 'sha256', 'a visitor' ), 0 );
		update_post_meta( $request, RequestPostType::META_SUBMITTER_EMAIL, 'gina@example.com' );

		$csv = $this->export( [ 'voters' => true ] );
		$this->assertStringStartsWith( "Request ID,Request,Voter,Email,Voted on\r\n", $csv );
		$this->assertStringContainsString( "\r\n{$request},Dark mode,Vera Voter,vera@example.com,", $csv );
		$this->assertStringContainsString( "\r\n{$request},Dark mode,Visitor,,", $csv );

		// As with --user: a user who sees the list but may not edit others' requests.
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author );
		wp_get_current_user()->add_cap( get_post_type_object( RequestPostType::NAME )->cap->edit_posts );

		$this->assertStringNotContainsString( '@', $this->export( [ 'voters' => true ] ) );
		$this->assertStringNotContainsString( 'gina@example.com', $this->export( [] ) );
	}

	/**
	 * Runs `wp mwtfrs export` with options and returns what it prints.
	 *
	 * @param array<string, mixed> $options Options.
	 */
	private function export( array $options ): string {
		ob_start();
		try {
			( new Commands() )->export( [], $options );
		} finally {
			$csv = (string) ob_get_clean();
		}

		return $csv;
	}

	/**
	 * Returns the request IDs in a requests export.
	 *
	 * @param string $csv The export.
	 * @return int[]
	 */
	private function ids( string $csv ): array {
		$lines = array_slice( array_filter( explode( "\r\n", $csv ) ), 1 );

		return array_map( 'intval', $lines );
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
