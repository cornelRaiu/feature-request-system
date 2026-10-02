<?php
/**
 * Tests for the votes and subscriptions of users who leave.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteRepository;

/**
 * Deleting a user, and on a network of sites also removing one from a site.
 */
final class DepartedUsersTest extends TestCase {

	public function test_the_votes_of_a_deleted_user_stay_but_are_no_longer_tied_to_the_account(): void {
		$request = $this->create_request();
		$leaving = self::factory()->user->create();
		$staying = self::factory()->user->create();
		$this->vote_and_follow( $request, $leaving );
		$this->vote_and_follow( $request, $staying );

		self::delete_user( $leaving );

		$this->assertSame( 2, VoteRepository::count( $request ), 'The count is what it was.' );
		$this->assertSame( 2, VoteCount::sync( $request ) );
		$this->assertSame( [], VoteRepository::of_user( $leaving, 10, 0 ) );
		$this->assertSame( [], VoteRepository::voted_among( hash( 'sha256', 'u:' . $leaving ), [ $request ] ), 'A new user who gets the same ID has not voted.' );
		$this->assertCount( 1, VoteRepository::of_user( $staying, 10, 0 ) );
		$this->assertSame( 0, SubscriptionRepository::count_of_user( $leaving ) );
		$this->assertSame( 1, SubscriptionRepository::count_of_user( $staying ) );
	}

	public function test_on_a_network_a_user_removed_from_a_site_stops_following_there_and_keeps_their_votes(): void {
		$this->skip_without_a_network();

		$request = $this->create_request();
		$leaving = self::factory()->user->create();
		$staying = self::factory()->user->create();
		foreach ( [ $leaving, $staying ] as $user ) {
			add_user_to_blog( get_current_blog_id(), $user, 'subscriber' );
			$this->vote_and_follow( $request, $user );
		}

		remove_user_from_blog( $leaving, get_current_blog_id() );

		$this->assertSame( 0, SubscriptionRepository::count_of_user( $leaving ) );
		$this->assertSame( 1, SubscriptionRepository::count_of_user( $staying ) );
		$this->assertCount( 1, VoteRepository::of_user( $leaving, 10, 0 ), 'The account still exists, and the vote is still theirs.' );
	}

	public function test_on_a_network_another_site_is_tidied_too_when_it_uses_the_plugin(): void {
		global $wpdb;

		$this->skip_without_a_network();

		$user  = self::factory()->user->create();
		$other = self::factory()->blog->create();
		$bare  = self::factory()->blog->create();
		add_user_to_blog( $other, $user, 'subscriber' );
		add_user_to_blog( $bare, $user, 'subscriber' );

		switch_to_blog( $other );
		Installer::install();
		$request = $this->create_request();
		$this->vote_and_follow( $request, $user );
		restore_current_blog();

		// The third site never had the plugin: there is nothing to tidy and no table to look in.
		$prefix  = $wpdb->get_blog_prefix( $bare );
		$touched = [];
		$watch   = static function ( string $query ) use ( &$touched, $prefix ): string {
			if ( false !== strpos( $query, $prefix . Schema::VOTES ) || false !== strpos( $query, $prefix . Schema::SUBSCRIPTIONS ) ) {
				$touched[] = $query;
			}

			return $query;
		};
		add_filter( 'query', $watch );
		remove_user_from_blog( $user, $bare );
		add_user_to_blog( $bare, $user, 'subscriber' );

		remove_user_from_blog( $user, $other );
		add_user_to_blog( $other, $user, 'subscriber' );
		switch_to_blog( $other );
		$follows = SubscriptionRepository::count_of_user( $user );
		$votes   = VoteRepository::of_user( $user, 10, 0 );
		restore_current_blog();
		$this->assertSame( 0, $follows, 'Removed from that site, the user follows nothing there.' );
		$this->assertCount( 1, $votes );

		wpmu_delete_user( $user );
		remove_filter( 'query', $watch );
		switch_to_blog( $other );
		$votes = VoteRepository::of_user( $user, 10, 0 );
		$count = VoteRepository::count( $request );
		$table = Schema::table( Schema::VOTES );
		restore_current_blog();

		$this->assertSame( [], $votes, 'Deleted from the network, the user has no vote on that site.' );
		$this->assertSame( 1, $count, 'The vote itself is still counted.' );
		$this->assertNotSame( Schema::table( Schema::VOTES ), $table, 'The other site has its own table.' );
		$this->assertSame( [], $touched, 'Nothing was asked of the site that never had the plugin.' );
	}

	/**
	 * Makes a user vote for a request and follow it.
	 *
	 * @param int $request_id Request ID.
	 * @param int $user_id    User ID.
	 */
	private function vote_and_follow( int $request_id, int $user_id ): void {
		VoteRepository::add( $request_id, hash( 'sha256', 'u:' . $user_id ), $user_id );
		SubscriptionRepository::add( $request_id, $user_id, SubscriptionRepository::SOURCE_VOTE );
	}

	/**
	 * Skips a test that needs a network of sites when the tests run on a single site.
	 */
	private function skip_without_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network of sites: run with WP_MULTISITE=1.' );
		}
	}
}
