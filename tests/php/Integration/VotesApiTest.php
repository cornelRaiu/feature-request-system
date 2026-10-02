<?php
/**
 * Vote REST API tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Rest\VotesController;
use FeatureRequestSystem\Security\RateLimiter;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Voting through POST and DELETE /mwtfrs/v1/requests/{id}/vote, the way the board's buttons will call it.
 */
final class VotesApiTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_logged_in_user_votes_once_however_often_they_click(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request = $this->create_request();

		$first  = $this->vote( 'POST', $request );
		$second = $this->vote( 'POST', $request );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( [ 'votes' => 1, 'voted' => true, 'following' => true ], $first->get_data() );
		$this->assertSame( [ 'votes' => 1, 'voted' => true, 'following' => true ], $second->get_data() );
		$this->assertCount( 1, $this->vote_rows( $request ) );
		$this->assertSame( '1', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	public function test_retracting_removes_the_vote_and_is_safe_to_repeat(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request = $this->create_request();
		$this->vote( 'POST', $request );

		$this->assertSame( [ 'votes' => 0, 'voted' => false, 'following' => false ], $this->vote( 'DELETE', $request )->get_data() );
		$this->assertSame( [ 'votes' => 0, 'voted' => false, 'following' => false ], $this->vote( 'DELETE', $request )->get_data() );
		$this->assertSame( [], $this->vote_rows( $request ) );
	}

	public function test_votes_from_different_users_add_up_per_request(): void {
		$popular = $this->create_request();
		$other   = $this->create_request();

		foreach ( range( 1, 3 ) as $voter ) {
			wp_set_current_user( self::factory()->user->create() );
			$response = $this->vote( 'POST', $popular );
		}
		$this->vote( 'POST', $other );

		$this->assertSame( [ 'votes' => 3, 'voted' => true, 'following' => true ], $response->get_data() );
		$this->assertSame( '1', get_post_meta( $other, RequestPostType::META_VOTES, true ) );
	}

	public function test_guests_must_log_in_to_vote_by_default(): void {
		$request = $this->create_request();

		$response = $this->vote( 'POST', $request, $this->same_site() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( VoteService::ERROR_LOGIN_REQUIRED, $response->get_data()['code'] );
		$this->assertSame( [], $this->vote_rows( $request ) );
		$this->assertArrayNotHasKey( VoterIdentity::COOKIE, $_COOKIE );
	}

	public function test_guest_vote_on_an_open_board_is_tied_to_a_new_voter_cookie(): void {
		$this->allow_guests();
		$request = $this->create_request();

		$response = $this->vote( 'POST', $request, $this->same_site() );

		$this->assertSame( [ 'votes' => 1, 'voted' => true ], $response->get_data() );
		$token = $_COOKIE[ VoterIdentity::COOKIE ] ?? '';
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );

		$rows = $this->vote_rows( $request );
		$this->assertSame( '0', $rows[0]->user_id );
		$this->assertSame( hash( 'sha256', 'g:' . $token ), $rows[0]->voter_key );
	}

	public function test_returning_guest_is_recognised_by_their_cookie(): void {
		$this->allow_guests();
		$request                          = $this->create_request();
		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'a1', 16 );

		$this->vote( 'POST', $request, $this->same_site() );
		$this->vote( 'POST', $request, $this->same_site() );
		$this->assertCount( 1, $this->vote_rows( $request ) );

		$this->assertSame( [ 'votes' => 0, 'voted' => false ], $this->vote( 'DELETE', $request, $this->same_site() )->get_data() );
	}

	public function test_malformed_guest_cookie_is_replaced(): void {
		$this->allow_guests();
		$_COOKIE[ VoterIdentity::COOKIE ] = 'not-a-token';

		$this->assertSame( 200, $this->vote( 'POST', $this->create_request(), $this->same_site() )->get_status() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $_COOKIE[ VoterIdentity::COOKIE ] );
	}

	public function test_guest_without_a_cookie_has_no_vote_to_retract(): void {
		$this->allow_guests();
		$request = $this->create_request();
		wp_set_current_user( self::factory()->user->create() );
		$this->vote( 'POST', $request );
		wp_set_current_user( 0 );

		$response = $this->vote( 'DELETE', $request, $this->same_site() );

		$this->assertSame( [ 'votes' => 1, 'voted' => false ], $response->get_data() );
		$this->assertCount( 1, $this->vote_rows( $request ) );
		$this->assertArrayNotHasKey( VoterIdentity::COOKIE, $_COOKIE );
	}

	public function test_guest_votes_sent_from_another_site_are_refused(): void {
		$this->allow_guests();
		$request = $this->create_request();

		$response = $this->vote( 'POST', $request, [ 'Origin' => 'https://evil.example' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( VotesController::ERROR_INVALID_ORIGIN, $response->get_data()['code'] );
		$this->assertSame( [], $this->vote_rows( $request ) );
		$this->assertArrayNotHasKey( VoterIdentity::COOKIE, $_COOKIE );
	}

	/**
	 * A link to .../vote?_method=POST on a page of this site arrives as a GET: with a
	 * Referer of this site and without an Origin.
	 */
	public function test_guest_votes_need_the_origin_header_and_a_referer_of_this_site_is_not_enough(): void {
		$this->allow_guests();
		$request = $this->create_request();

		foreach ( [ 'POST', 'DELETE' ] as $method ) {
			$response = $this->vote( $method, $request, [ 'Referer' => home_url( '/roadmap/?sort=top' ) ] );

			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( VotesController::ERROR_INVALID_ORIGIN, $response->get_data()['code'] );
		}
		$this->assertSame( 403, $this->vote( 'POST', $request )->get_status() );
		$this->assertSame( [], $this->vote_rows( $request ) );
		$this->assertArrayNotHasKey( VoterIdentity::COOKIE, $_COOKIE );
	}

	public function test_logged_in_users_rely_on_the_rest_nonce_instead_of_the_origin(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( 200, $this->vote( 'POST', $this->create_request(), [ 'Origin' => 'https://evil.example' ] )->get_status() );
	}

	public function test_boards_can_turn_voting_off(): void {
		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::NOBODY ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->vote( 'POST', $this->create_request() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( VoteService::ERROR_DISABLED, $response->get_data()['code'] );
	}

	public function test_closed_status_freezes_the_vote_count(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request();
		$this->vote( 'POST', $request );
		wp_set_object_terms( $request, $this->status( 'shipped' ), StatusTaxonomy::NAME );

		foreach ( [ 'POST', 'DELETE' ] as $method ) {
			$response = $this->vote( $method, $request );
			$this->assertSame( 403, $response->get_status(), $method );
			$this->assertSame( VoteService::ERROR_CLOSED, $response->get_data()['code'], $method );
		}
		$this->assertSame( '1', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	public function test_only_published_requests_accept_votes(): void {
		wp_set_current_user( self::factory()->user->create() );
		$not_votable = [
			'pending'   => $this->create_request( [ 'post_status' => 'pending' ] ),
			'draft'     => $this->create_request( [ 'post_status' => 'draft' ] ),
			'private'   => $this->create_request( [ 'post_status' => 'private' ] ),
			'password'  => $this->create_request( [ 'post_password' => 'secret' ] ),
			'blog post' => self::factory()->post->create(),
			'missing'   => 999999,
		];

		foreach ( $not_votable as $case => $id ) {
			$response = $this->vote( 'POST', $id );
			$this->assertSame( 404, $response->get_status(), $case );
			$this->assertSame( VoteService::ERROR_NOT_FOUND, $response->get_data()['code'], $case );
		}
	}

	public function test_too_many_votes_return_429_with_retry_after(): void {
		add_filter(
			RateLimiter::FILTER_LIMIT,
			static function () {
				return 2;
			}
		);
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request();

		$this->vote( 'POST', $request );
		$this->vote( 'DELETE', $request );
		$response = $this->vote( 'POST', $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( VoteService::ERROR_RATE_LIMITED, $response->get_data()['code'] );
		$retry_after = (int) $response->get_headers()['Retry-After'];
		$this->assertGreaterThan( 0, $retry_after );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $retry_after );
		$this->assertSame( [], $this->vote_rows( $request ) );
	}

	public function test_ip_limit_counts_every_guest_from_the_same_address(): void {
		$this->allow_guests();
		add_filter(
			RateLimiter::FILTER_LIMIT,
			static function ( int $limit, string $action, string $scope ): int {
				return 'ip' === $scope ? 2 : $limit;
			},
			10,
			3
		);
		$request = $this->create_request();

		foreach ( [ 200, 200, 429 ] as $expected ) {
			unset( $_COOKIE[ VoterIdentity::COOKIE ] );
			$this->assertSame( $expected, $this->vote( 'POST', $request, $this->same_site() )->get_status() );
		}

		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 200, $this->vote( 'POST', $request, $this->same_site() )->get_status(), 'Another address has its own limit.' );
	}

	public function test_moderators_are_not_rate_limited(): void {
		add_filter(
			RateLimiter::FILTER_LIMIT,
			static function () {
				return 1;
			}
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = $this->create_request();

		foreach ( [ 'POST', 'DELETE', 'POST' ] as $method ) {
			$this->assertSame( 200, $this->vote( $method, $request )->get_status(), $method );
		}
	}

	public function test_a_wrong_stored_count_is_corrected_by_the_next_vote(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request();
		update_post_meta( $request, RequestPostType::META_VOTES, 99 );

		$this->assertSame( 1, $this->vote( 'POST', $request )->get_data()['votes'] );
		$this->assertSame( '1', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	public function test_permanently_deleting_a_request_deletes_its_votes(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request();
		$this->vote( 'POST', $request );

		wp_trash_post( $request );
		$this->assertCount( 1, $this->vote_rows( $request ), 'Trashing keeps votes so the request can be restored.' );

		wp_delete_post( $request, true );
		$this->assertSame( [], $this->vote_rows( $request ) );
	}

	/**
	 * Sends a vote request through the REST server.
	 *
	 * @param string                $method     POST to vote, DELETE to retract.
	 * @param int                   $request_id Request ID.
	 * @param array<string, string> $headers    Request headers.
	 */
	private function vote( string $method, int $request_id, array $headers = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestApi::NAMESPACE . '/requests/' . $request_id . '/vote' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Headers a browser sends with a vote from a page on this site.
	 *
	 * @return array<string, string>
	 */
	private function same_site(): array {
		return [ 'Origin' => home_url() ];
	}

	/**
	 * Lets guests vote on the default board.
	 */
	private function allow_guests(): void {
		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
	}

	/**
	 * Returns the vote rows stored for a request.
	 *
	 * @param int $request_id Request ID.
	 * @return object[]
	 */
	private function vote_rows( int $request_id ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, voter_key FROM %i WHERE request_id = %d', Schema::table( Schema::VOTES ), $request_id ) );
	}
}
