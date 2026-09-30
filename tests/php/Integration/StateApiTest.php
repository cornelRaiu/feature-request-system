<?php
/**
 * Visitor state REST route tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /mwtfrs/v1/me/state tells a page which requests the visitor voted for.
 */
final class StateApiTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_logged_in_user_learns_which_requests_they_voted_for(): void {
		wp_set_current_user( self::factory()->user->create() );
		$voted     = $this->create_request();
		$not_voted = $this->create_request();
		VoteService::cast( $voted );

		$response = $this->state( [ $voted, $not_voted ] );

		$this->assertSame( [ 'voted' => [ $voted ] ], $response->get_data() );
	}

	public function test_guest_is_recognised_by_their_voter_cookie(): void {
		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$request                          = $this->create_request();
		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'c3', 16 );
		VoteService::cast( $request );

		$this->assertSame( [ 'voted' => [ $request ] ], $this->state( [ $request ] )->get_data() );
	}

	public function test_guest_without_a_cookie_gets_no_votes_and_no_cookie(): void {
		$request = $this->create_request();
		wp_set_current_user( self::factory()->user->create() );
		VoteService::cast( $request );
		wp_set_current_user( 0 );

		$this->assertSame( [ 'voted' => [] ], $this->state( [ $request ] )->get_data() );
		$this->assertArrayNotHasKey( VoterIdentity::COOKIE, $_COOKIE );
	}

	public function test_the_answer_is_never_cached(): void {
		$this->assertSame( 'no-store', $this->state( [ $this->create_request() ] )->get_headers()['Cache-Control'] );
	}

	public function test_one_call_may_ask_about_at_most_100_requests(): void {
		$this->assertSame( 400, $this->state( range( 1, 101 ) )->get_status() );
	}

	/**
	 * Calls the route.
	 *
	 * @param int[] $ids Request IDs.
	 */
	private function state( array $ids ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/me/state' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'ids' => $ids ] ) );

		return rest_do_request( $request );
	}
}
