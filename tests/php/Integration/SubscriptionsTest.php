<?php
/**
 * Following tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Who follows a request, and what starts and ends it: a vote, a submission, or the user's own choice.
 */
final class SubscriptionsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_voting_follows_the_request_and_taking_the_vote_back_ends_it(): void {
		$user    = $this->log_in();
		$request = $this->create_request();

		VoteService::cast( $request );
		VoteService::cast( $request );

		$this->assertTrue( Subscriptions::is_following( $request, $user ) );
		$this->assertCount( 1, $this->rows( $request ), 'Voting again does not follow twice.' );
		$this->assertSame( SubscriptionRepository::SOURCE_VOTE, $this->rows( $request )[0]->source );

		VoteService::retract( $request );

		$this->assertFalse( Subscriptions::is_following( $request, $user ) );
	}

	public function test_a_vote_is_announced_once_and_so_is_taking_it_back(): void {
		$user    = $this->log_in();
		$request = $this->create_request();
		$heard   = [];
		foreach ( [ VoteService::ACTION_CAST, VoteService::ACTION_RETRACTED ] as $action ) {
			add_action(
				$action,
				static function ( int $request_id, int $user_id ) use ( $action, &$heard ): void {
					$heard[] = [ $action, $request_id, $user_id ];
				},
				10,
				2
			);
		}

		VoteService::retract( $request );
		VoteService::cast( $request );
		VoteService::cast( $request );
		VoteService::retract( $request );
		VoteService::retract( $request );

		$this->assertSame(
			[
				[ VoteService::ACTION_CAST, $request, $user ],
				[ VoteService::ACTION_RETRACTED, $request, $user ],
			],
			$heard
		);
	}

	public function test_a_guest_who_votes_follows_nothing(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$request = $this->create_request();

		$this->assertSame( 1, VoteService::cast( $request )['votes'] );
		$this->assertSame( [], $this->rows( $request ) );
	}

	public function test_voting_does_not_follow_when_the_site_turned_that_off(): void {
		Settings::update( Settings::SUBSCRIBE_VOTERS, false );
		$user    = $this->log_in();
		$request = $this->create_request();

		VoteService::cast( $request );

		$this->assertFalse( Subscriptions::is_following( $request, $user ) );
		$this->assertSame( [ 'following' => true ], Subscriptions::follow( $request ), 'Choosing to follow still works.' );
	}

	public function test_voting_does_not_follow_for_a_user_who_turned_that_off(): void {
		$user    = $this->log_in();
		$request = $this->create_request();
		$this->assertTrue( Subscriptions::wants_automatic( $user ) );

		Subscriptions::set_automatic( $user, false );
		VoteService::cast( $request );

		$this->assertFalse( Subscriptions::wants_automatic( $user ) );
		$this->assertFalse( Subscriptions::is_following( $request, $user ) );

		Subscriptions::set_automatic( $user, true );
		VoteService::retract( $request );
		VoteService::cast( $request );

		$this->assertTrue( Subscriptions::is_following( $request, $user ) );
	}

	public function test_nobody_follows_while_the_site_does_not_email_followers(): void {
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		BoardSettings::update( $this->default_board(), [ BoardSettings::MODERATION => false ] );
		$user    = $this->log_in();
		$request = $this->create_request();

		VoteService::cast( $request );
		$followed  = Subscriptions::follow( $request );
		$submitted = SubmissionService::submit( $this->default_board(), $this->submission() );

		$this->assertSame( Subscriptions::ERROR_DISABLED, $followed->get_error_code() );
		$this->assertSame( 403, $followed->get_error_data()['status'] );
		$this->assertFalse( Subscriptions::is_following( $request, $user ) );
		$this->assertFalse( Subscriptions::is_following( $submitted['id'], $user ) );
	}

	public function test_choosing_to_follow_outlasts_the_vote_whichever_came_first(): void {
		$user      = $this->log_in();
		$voted     = $this->create_request();
		$followed  = $this->create_request();
		$unvoted   = $this->create_request();
		$abandoned = $this->create_request();

		VoteService::cast( $voted );
		Subscriptions::follow( $voted );
		VoteService::retract( $voted );

		Subscriptions::follow( $followed );
		VoteService::cast( $followed );
		VoteService::retract( $followed );

		Subscriptions::follow( $unvoted );
		VoteService::retract( $unvoted );

		VoteService::cast( $abandoned );
		Subscriptions::unfollow( $abandoned );

		$this->assertTrue( Subscriptions::is_following( $voted, $user ), 'Voted, then chose to follow, then took the vote back.' );
		$this->assertTrue( Subscriptions::is_following( $followed, $user ), 'Chose to follow, then voted and took the vote back.' );
		$this->assertTrue( Subscriptions::is_following( $unvoted, $user ), 'Chose to follow without ever voting.' );
		$this->assertFalse( Subscriptions::is_following( $abandoned, $user ), 'Stopped following; the vote stays.' );
		$this->assertSame( 1, VoteService::cast( $abandoned )['votes'] );
		$this->assertFalse( Subscriptions::is_following( $abandoned, $user ), 'Voting again for a request already voted for does not follow it again.' );
	}

	public function test_a_logged_in_user_follows_the_request_they_suggest(): void {
		$user = $this->log_in();

		$request = SubmissionService::submit( $this->default_board(), $this->submission() )['id'];

		$this->assertSame( 'pending', get_post_status( $request ) );
		$this->assertSame( SubscriptionRepository::SOURCE_AUTHOR, $this->rows( $request )[0]->source );
		$this->assertSame( (string) $user, $this->rows( $request )[0]->user_id );

		wp_publish_post( $request );
		VoteService::cast( $request );
		VoteService::retract( $request );

		$this->assertTrue( Subscriptions::is_following( $request, $user ), 'Voting for their own request and taking it back changes nothing.' );
	}

	public function test_a_user_who_turned_following_off_does_not_follow_what_they_suggest(): void {
		$user = $this->log_in();
		Subscriptions::set_automatic( $user, false );

		$request = SubmissionService::submit( $this->default_board(), $this->submission() )['id'];

		$this->assertSame( [], $this->rows( $request ) );
	}

	public function test_a_guest_who_suggests_a_request_follows_nothing(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );

		$request = SubmissionService::submit( $this->default_board(), $this->submission( [ 'email' => 'ana@example.com' ] ) )['id'];

		$this->assertSame( 'ana@example.com', get_post_meta( $request, RequestPostType::META_SUBMITTER_EMAIL, true ) );
		$this->assertSame( [], $this->rows( $request ) );
	}

	public function test_only_logged_in_users_follow_and_only_requests_anyone_can_see(): void {
		$public    = $this->create_request();
		$pending   = $this->create_request( [ 'post_status' => 'pending' ] );
		$protected = $this->create_request( [ 'post_password' => 'secret' ] );
		$post      = self::factory()->post->create();

		$guest = Subscriptions::follow( $public );
		$this->assertSame( Subscriptions::ERROR_LOGIN_REQUIRED, $guest->get_error_code() );
		$this->assertSame( 401, $guest->get_error_data()['status'] );
		$this->assertSame( Subscriptions::ERROR_LOGIN_REQUIRED, Subscriptions::unfollow( $public )->get_error_code() );

		$user = $this->log_in();
		foreach ( [ $pending, $protected, $post, 999999 ] as $hidden ) {
			$refused = Subscriptions::follow( $hidden );
			$this->assertSame( VoteService::ERROR_NOT_FOUND, $refused->get_error_code(), (string) $hidden );
			$this->assertSame( 404, $refused->get_error_data()['status'] );
			$this->assertFalse( Subscriptions::is_following( $hidden, $user ) );
		}

		$this->assertSame( [ 'following' => true ], Subscriptions::follow( $public ) );
	}

	public function test_a_user_can_stop_following_a_request_that_is_no_longer_public(): void {
		$user    = $this->log_in();
		$request = $this->create_request();
		Subscriptions::follow( $request );
		wp_trash_post( $request );

		$this->assertTrue( Subscriptions::is_following( $request, $user ), 'Trashing a request keeps its followers, in case it is restored.' );
		$this->assertSame( [ 'following' => false ], Subscriptions::unfollow( $request ) );
		$this->assertFalse( Subscriptions::is_following( $request, $user ) );
	}

	public function test_following_twice_keeps_one_subscription_and_its_link(): void {
		$user    = $this->log_in();
		$request = $this->create_request();

		$this->assertTrue( SubscriptionRepository::add( $request, $user, SubscriptionRepository::SOURCE_FOLLOW ) );
		$first = SubscriptionRepository::find( $request, $user );

		$this->assertFalse( SubscriptionRepository::add( $request, $user, SubscriptionRepository::SOURCE_FOLLOW ) );
		$this->assertFalse( SubscriptionRepository::add( $request, $user, SubscriptionRepository::SOURCE_VOTE ) );

		$this->assertSame( $first, SubscriptionRepository::find( $request, $user ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $first['token'] );
		$this->assertCount( 1, $this->rows( $request ) );
	}

	public function test_every_subscription_has_its_own_link(): void {
		$request = $this->create_request();
		$this->create_followers( $request, 5 );

		$this->assertCount( 5, array_unique( wp_list_pluck( $this->rows( $request ), 'token' ) ) );
	}

	public function test_deleting_a_request_or_a_user_removes_their_subscriptions(): void {
		$kept    = $this->create_request();
		$deleted = $this->create_request();
		$stays   = $this->create_followers( $kept, 1 )[0];
		$leaves  = $this->create_followers( $kept, 1 )[0];
		SubscriptionRepository::add( $deleted, $stays, SubscriptionRepository::SOURCE_FOLLOW );

		wp_delete_post( $deleted, true );
		wp_delete_post( self::factory()->post->create(), true );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $leaves );

		$this->assertSame( [], $this->rows( $deleted ) );
		$this->assertSame( [ (string) $stays ], wp_list_pluck( $this->rows( $kept ), 'user_id' ) );
	}

	public function test_the_rest_api_follows_and_stops_following(): void {
		$user    = $this->log_in();
		$request = $this->create_request();

		$followed = $this->follow_request( 'POST', $request );
		$this->assertSame( 200, $followed->get_status() );
		$this->assertSame( [ 'following' => true ], $followed->get_data() );
		$this->assertTrue( Subscriptions::is_following( $request, $user ) );

		$this->assertSame( [ 'following' => false ], $this->follow_request( 'DELETE', $request )->get_data() );
		$this->assertFalse( Subscriptions::is_following( $request, $user ) );
	}

	public function test_the_rest_api_refuses_guests_and_requests_that_are_not_public(): void {
		$public  = $this->create_request();
		$pending = $this->create_request( [ 'post_status' => 'pending' ] );

		$guest = $this->follow_request( 'POST', $public );
		$this->assertSame( 401, $guest->get_status() );
		$this->assertSame( Subscriptions::ERROR_LOGIN_REQUIRED, $guest->get_data()['code'] );
		$this->assertSame( 401, $this->follow_request( 'DELETE', $public )->get_status() );

		$this->log_in();
		$hidden = $this->follow_request( 'POST', $pending );
		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( VoteService::ERROR_NOT_FOUND, $hidden->get_data()['code'] );
		$this->assertSame( [], $this->rows( $public ) );
		$this->assertSame( [], $this->rows( $pending ) );
	}

	/**
	 * Logs in as a new subscriber and returns their ID.
	 */
	private function log_in(): int {
		$user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user );

		return $user;
	}

	/**
	 * Calls the follow route for a request.
	 *
	 * @param string $method     POST to follow, DELETE to stop.
	 * @param int    $request_id Request ID.
	 */
	private function follow_request( string $method, int $request_id ): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, '/' . RestApi::NAMESPACE . '/requests/' . $request_id . '/subscription' ) );
	}

	/**
	 * Returns the subscription rows of a request, oldest first.
	 *
	 * @param int $request_id Request ID.
	 * @return array<int, object>
	 */
	private function rows( int $request_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id ASC', Schema::table( Schema::SUBSCRIPTIONS ), $request_id )
		);
	}
}
