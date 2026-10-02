<?php
/**
 * Rate limiter tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Security\RateLimiter;
use FeatureRequestSystem\Settings;

/**
 * The hourly limits per visitor and per address. How they stop votes and submissions is
 * covered by the tests of those.
 */
final class RateLimiterTest extends TestCase {

	/**
	 * Whether the site had a persistent object cache before the test.
	 *
	 * @var bool
	 */
	private $had_object_cache = false;

	public function set_up(): void {
		parent::set_up();
		$this->had_object_cache = (bool) wp_using_ext_object_cache();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	public function tear_down(): void {
		wp_using_ext_object_cache( $this->had_object_cache );
		parent::tear_down();
	}

	public function test_attempts_pass_up_to_the_limit_and_are_refused_after_it(): void {
		$this->limit( 2, 100 );

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );

		$wait = RateLimiter::attempt( 'vote', 'visitor-a' );
		$this->assertGreaterThan( 0, $wait );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $wait );

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-b' ), 'Another visitor has their own limit.' );
		$this->assertSame( 0, RateLimiter::attempt( 'submit', 'visitor-a' ), 'Another action has its own limit.' );
	}

	public function test_one_visitor_gets_30_votes_and_5_requests_an_hour(): void {
		foreach ( range( 1, 30 ) as $number ) {
			$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ), 'Vote ' . $number );
		}
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );

		foreach ( range( 1, 5 ) as $number ) {
			$this->assertSame( 0, RateLimiter::attempt( 'submit', 'visitor-a' ), 'Request ' . $number );
		}
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'submit', 'visitor-a' ) );
	}

	public function test_an_address_gets_60_votes_an_hour_from_guests_and_60_from_logged_in_users(): void {
		foreach ( range( 1, 60 ) as $number ) {
			$this->assertSame( 0, RateLimiter::attempt( 'vote', 'guest-' . $number ), 'Guest ' . $number );
		}
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'guest-61' ) );

		wp_set_current_user( self::factory()->user->create() );
		foreach ( range( 1, 60 ) as $number ) {
			$this->assertSame( 0, RateLimiter::attempt( 'vote', 'user-' . $number ), 'User ' . $number );
		}
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'user-61' ) );
	}

	public function test_the_settings_say_how_many_votes_an_address_gets_and_each_group_has_its_own_count(): void {
		Settings::update( Settings::VOTES_PER_ADDRESS_GUESTS, 2 );
		Settings::update( Settings::VOTES_PER_ADDRESS_USERS, 3 );

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'guest-1' ) );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'guest-2' ) );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'guest-3' ), 'The third vote of a guest at this address.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'user-1' ), 'What guests did is not counted against logged-in users.' );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'user-2' ) );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'user-3' ) );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'user-4' ), 'The fourth vote of a logged-in user at this address.' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'user-5' ), 'Another address has its own counts.' );
	}

	public function test_a_stored_limit_below_one_counts_as_the_default(): void {
		Settings::update( Settings::VOTES_PER_ADDRESS_GUESTS, 0 );
		Settings::update( Settings::VOTES_PER_ADDRESS_USERS, 'none' );

		$this->assertSame( 60, Settings::votes_per_address( false ) );
		$this->assertSame( 60, Settings::votes_per_address( true ) );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'guest-1' ), 'Voting is not shut off by a number that makes no sense.' );
	}

	public function test_a_filter_has_the_last_word_on_the_limits_from_the_settings(): void {
		Settings::update( Settings::VOTES_PER_ADDRESS_GUESTS, 5 );
		$seen = [];
		add_filter(
			RateLimiter::FILTER_LIMIT,
			static function ( int $limit, string $action, string $scope ) use ( &$seen ): int {
				$seen[] = [ $limit, $action, $scope ];

				return 'ip' === $scope ? 1 : $limit;
			},
			10,
			3
		);

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'guest-1' ) );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'guest-2' ) );
		$this->assertContains( [ 5, 'vote', 'ip' ], $seen, 'The filter is given the number from the settings.' );
	}

	public function test_requests_sent_from_one_address_share_one_limit_whoever_sends_them(): void {
		$user = self::factory()->user->create();
		foreach ( range( 1, 10 ) as $number ) {
			wp_set_current_user( $number % 2 ? $user : 0 );
			$this->assertSame( 0, RateLimiter::attempt( 'submit', 'visitor-' . $number ), 'Request ' . $number );
		}

		$this->assertGreaterThan( 0, RateLimiter::attempt( 'submit', 'visitor-11' ), 'As a guest.' );
		wp_set_current_user( $user );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'submit', 'visitor-12' ), 'As a logged-in user.' );
	}

	/**
	 * Two requests that arrive together must not both pass on the same count. The second
	 * one is played here by running the counter's own statement once more, at the moment
	 * the first is about to write.
	 */
	public function test_an_attempt_that_arrives_at_the_same_moment_is_counted_too(): void {
		global $wpdb;

		$this->limit( 1, 100 );
		$arrived = false;
		$other   = static function ( string $query ) use ( &$arrived, $wpdb ): string {
			if ( ! $arrived && preg_match( '/^\s*(INSERT|UPDATE)\b.*_transient_' . RateLimiter::TRANSIENT_PREFIX . 'vote_voter_/is', $query ) ) {
				$arrived = true;
				$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The limiter's own prepared statement, run a second time.
			}

			return $query;
		};

		add_filter( 'query', $other );
		$wait = RateLimiter::attempt( 'vote', 'visitor-a' );
		remove_filter( 'query', $other );

		$this->assertTrue( $arrived );
		$this->assertGreaterThan( 0, $wait, 'Two attempts were made and the limit is one.' );
	}

	public function test_a_visitor_over_their_own_limit_does_not_use_up_the_limit_of_their_address(): void {
		$this->limit( 1, 3 );

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-b' ), 'The second attempt from this address.' );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-c' ), 'The third attempt from this address.' );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-d' ), 'The fourth attempt from this address.' );
	}

	public function test_an_ipv6_network_is_counted_as_one_address(): void {
		$this->limit( 100, 2 );

		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-b' ) );
		$_SERVER['REMOTE_ADDR'] = '2001:DB8:1:2:ffff:ffff:ffff:ffff';
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-c' ), 'A third address in the same network.' );

		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-d' ), 'Another network has its own limit.' );
	}

	public function test_an_ipv4_address_written_as_ipv6_is_counted_as_that_address(): void {
		$this->limit( 100, 2 );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$_SERVER['REMOTE_ADDR'] = '::ffff:198.51.100.7';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-b' ) );
		$_SERVER['REMOTE_ADDR'] = '::ffff:c633:6407';
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-c' ), 'The same address, written a third way.' );

		$_SERVER['REMOTE_ADDR'] = '::ffff:198.51.100.8';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-d' ), 'Another IPv4 address is not in the same network.' );
	}

	public function test_without_a_valid_address_only_the_visitor_is_counted(): void {
		$this->limit( 100, 0 );

		$_SERVER['REMOTE_ADDR'] = 'unknown';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-a' ), 'An address with a limit of none.' );
	}

	public function test_a_site_behind_a_proxy_can_say_what_the_address_of_a_visitor_is(): void {
		$this->limit( 100, 1 );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$forwarded              = '198.51.100.1';
		add_filter(
			RateLimiter::FILTER_CLIENT_IP,
			static function ( string $ip ) use ( &$forwarded ): string {
				return '10.0.0.1' === $ip ? $forwarded : $ip;
			}
		);

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );

		$forwarded = '198.51.100.2';
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-b' ), 'Another visitor behind the same proxy.' );

		$forwarded = '198.51.100.1';
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-c' ) );
	}

	public function test_the_counters_expire_and_wordpress_deletes_them(): void {
		global $wpdb;

		RateLimiter::attempt( 'vote', 'visitor-a' );
		RateLimiter::attempt( 'vote', 'visitor-a' );

		$rows = $this->counter_rows();
		$this->assertCount( 4, $rows, 'A counter and its expiry for the visitor, and the same for the address.' );
		foreach ( $rows as $row ) {
			$this->assertSame( 'off', $row->autoload, $row->option_name );

			if ( 0 === strpos( $row->option_name, '_transient_timeout_' ) ) {
				$this->assertGreaterThan( time(), (int) $row->option_value );
				$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, (int) $row->option_value );
			} else {
				$this->assertSame( '2', $row->option_value );
			}
		}
		$this->assertStringNotContainsString( '203.0.113.10', (string) wp_json_encode( $rows ), 'The address itself is not stored.' );

		// The hour is over.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %d WHERE option_name LIKE %s',
				$wpdb->options,
				time() - 1,
				$wpdb->esc_like( '_transient_timeout_' . RateLimiter::TRANSIENT_PREFIX ) . '%'
			)
		);
		delete_expired_transients( true );

		$this->assertSame( [], $this->counter_rows() );
	}

	public function test_a_site_with_a_persistent_object_cache_counts_there(): void {
		wp_using_ext_object_cache( true );
		$this->limit( 2, 100 );

		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'vote', 'visitor-a' ) );
		$this->assertSame( 0, RateLimiter::attempt( 'vote', 'visitor-b' ) );

		$this->assertSame( [], $this->counter_rows() );
	}

	public function test_a_cache_that_cannot_count_does_not_stop_visitors(): void {
		wp_using_ext_object_cache( true );
		$this->limit( 1, 1 );
		// Nothing can be added to the cache, as when its server is down.
		wp_suspend_cache_addition( true );

		$first  = RateLimiter::attempt( 'vote', 'visitor-a' );
		$second = RateLimiter::attempt( 'vote', 'visitor-a' );
		wp_suspend_cache_addition( false );

		$this->assertSame( [ 0, 0 ], [ $first, $second ] );
	}

	public function test_a_limit_of_none_holds_also_when_the_cache_cannot_count(): void {
		wp_using_ext_object_cache( true );
		$this->limit( 0, 0 );
		wp_suspend_cache_addition( true );

		$wait = RateLimiter::attempt( 'vote', 'visitor-a' );
		wp_suspend_cache_addition( false );

		$this->assertGreaterThan( 0, $wait );
	}

	/**
	 * Sets the limits for every action.
	 *
	 * @param int $per_visitor Attempts an hour for one visitor.
	 * @param int $per_address Attempts an hour for one address.
	 */
	private function limit( int $per_visitor, int $per_address ): void {
		add_filter(
			RateLimiter::FILTER_LIMIT,
			static function ( int $limit, string $action, string $scope ) use ( $per_visitor, $per_address ): int {
				return 'ip' === $scope ? $per_address : $per_visitor;
			},
			5,
			3
		);
	}

	/**
	 * Returns the rows that counters have in the options table.
	 *
	 * @return object[]
	 */
	private function counter_rows(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s ORDER BY option_name',
				$wpdb->options,
				'%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%'
			)
		);
	}
}
