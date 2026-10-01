<?php
/**
 * Request submission REST API tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Rest\VotesController;
use FeatureRequestSystem\Security\RateLimiter;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Voting\VoterIdentity;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Submitting through POST /mwtfrs/v1/boards/{board}/requests, the way the form block calls it.
 */
final class SubmissionApiTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_a_logged_in_users_request_waits_for_approval(): void {
		$user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user );

		$response = $this->submit();
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( '', $data['url'], 'A pending request has no public page yet.' );
		$this->assertSame( 'Thanks! Your request will appear on the board once it is approved.', $data['message'] );

		$request = get_post( $data['id'] );
		$this->assertSame( RequestPostType::NAME, $request->post_type );
		$this->assertSame( 'pending', $request->post_status );
		$this->assertSame( 'Export to CSV', $request->post_title );
		$this->assertSame( 'For our accountant.', $request->post_content );
		$this->assertSame( [ $this->default_board() ], $this->term_ids( $request->ID, BoardTaxonomy::NAME ) );
		$this->assertSame( [ RequestTerms::default_term( StatusTaxonomy::NAME ) ], $this->term_ids( $request->ID, StatusTaxonomy::NAME ) );
		$this->assertSame( '0', get_post_meta( $request->ID, RequestPostType::META_VOTES, true ) );
	}

	public function test_submitters_are_recorded_privately_instead_of_as_post_authors(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$request = (int) $this->submit()->get_data()['id'];

		$this->assertSame( '0', get_post( $request )->post_author );
		$this->assertSame( (string) $user, get_post_meta( $request, RequestPostType::META_SUBMITTER_ID, true ) );
	}

	public function test_moderators_publish_straight_away(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$data = $this->submit()->get_data();

		$this->assertSame( 'publish', $data['status'] );
		$this->assertSame( get_permalink( $data['id'] ), $data['url'] );
		$this->assertSame( 'Thanks! Your request is on the board.', $data['message'] );
	}

	public function test_boards_without_moderation_publish_everyones_requests(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::MODERATION => false ] );
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( 'publish', $this->submit()->get_data()['status'] );
	}

	public function test_requests_go_to_the_board_in_the_url_with_the_chosen_category(): void {
		$board    = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		$category = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		wp_set_current_user( self::factory()->user->create() );

		$request = (int) $this->submit( [ 'category' => (string) $category ], [], $board )->get_data()['id'];

		$this->assertSame( [ $board ], $this->term_ids( $request, BoardTaxonomy::NAME ) );
		$this->assertSame( [ $category ], $this->term_ids( $request, CategoryTaxonomy::NAME ) );
	}

	public function test_guests_must_log_in_to_submit_by_default(): void {
		$response = $this->submit( [], $this->same_site() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( SubmissionService::ERROR_LOGIN_REQUIRED, $response->get_data()['code'] );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_guests_can_submit_to_boards_that_allow_them(): void {
		$this->allow_guests();

		$response = $this->submit(
			[
				'name'  => 'Ana Guestington',
				'email' => 'ana@example.com',
			],
			$this->same_site()
		);
		$request  = (int) $response->get_data()['id'];

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', get_post_status( $request ) );
		$this->assertSame( 'Ana Guestington', get_post_meta( $request, RequestPostType::META_SUBMITTER_NAME, true ) );
		$this->assertSame( 'ana@example.com', get_post_meta( $request, RequestPostType::META_SUBMITTER_EMAIL, true ) );
		$this->assertSame( '', get_post_meta( $request, RequestPostType::META_SUBMITTER_ID, true ) );
	}

	public function test_guest_names_and_emails_never_appear_in_the_core_rest_api(): void {
		$this->allow_guests();
		$request = (int) $this->submit(
			[
				'name'  => 'Ana Guestington',
				'email' => 'ana@example.com',
			],
			$this->same_site()
		)->get_data()['id'];
		wp_publish_post( $request );

		$visitor = rest_do_request( new WP_REST_Request( 'GET', rest_get_route_for_post( $request ) ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$editor = new WP_REST_Request( 'GET', rest_get_route_for_post( $request ) );
		$editor->set_param( 'context', 'edit' );
		$admin = rest_do_request( $editor );

		foreach ( [ 'visitor' => $visitor, 'admin' => $admin ] as $who => $response ) {
			$json = (string) wp_json_encode( $response->get_data() );
			$this->assertSame( 200, $response->get_status(), $who );
			$this->assertStringNotContainsString( 'ana@example.com', $json, $who );
			$this->assertStringNotContainsString( 'Guestington', $json, $who );
			$this->assertArrayNotHasKey( 'author', $response->get_data(), $who );
		}
	}

	public function test_guest_submissions_from_another_site_are_refused(): void {
		$this->allow_guests();

		$response = $this->submit( [], [ 'Origin' => 'https://evil.example' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( VotesController::ERROR_INVALID_ORIGIN, $response->get_data()['code'] );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_boards_can_stop_taking_requests(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::NOBODY ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->submit();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( SubmissionService::ERROR_CLOSED, $response->get_data()['code'] );
	}

	public function test_unknown_boards_are_not_found(): void {
		wp_set_current_user( self::factory()->user->create() );

		$response = $this->submit( [], [], 999999 );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( SubmissionService::ERROR_BOARD_NOT_FOUND, $response->get_data()['code'] );
	}

	public function test_bots_are_refused_without_being_told_which_check_failed(): void {
		wp_set_current_user( self::factory()->user->create() );
		$cases = [
			'trap field filled in'  => [ SpamGuard::FIELD_TRAP => 'https://spam.example' ],
			'no token'              => [ SpamGuard::FIELD_TOKEN => '' ],
			'forged token'          => [ SpamGuard::FIELD_TOKEN => ( time() - 60 ) . '.forged' ],
			'sent right after load' => [ SpamGuard::FIELD_TOKEN => SpamGuard::token() ],
			'token from the future' => [ SpamGuard::FIELD_TOKEN => SpamGuard::token( time() + 60 ) ],
		];

		foreach ( $cases as $case => $fields ) {
			$response = $this->submit( $fields );
			$this->assertSame( 400, $response->get_status(), $case );
			$this->assertSame( SpamGuard::ERROR_REJECTED, $response->get_data()['code'], $case );
			$this->assertSame( 'Your request could not be sent. Reload the page and try again.', $response->get_data()['message'], $case );
		}
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_captcha_plugins_can_refuse_a_submission(): void {
		add_filter(
			SpamGuard::FILTER_CAPTCHA,
			static function () {
				return new WP_Error( 'captcha_failed', 'Solve the puzzle first.', [ 'status' => 400 ] );
			}
		);
		wp_set_current_user( self::factory()->user->create() );

		$response = $this->submit();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'captcha_failed', $response->get_data()['code'] );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_requests_a_spam_check_flags_are_held_for_review(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::MODERATION => false ] );
		add_filter( SpamGuard::FILTER_SPAM, '__return_true' );
		wp_set_current_user( self::factory()->user->create() );

		$data = $this->submit()->get_data();

		$this->assertSame( 'pending', $data['status'], 'Held even though the board publishes requests straight away.' );
		$this->assertSame( '1', get_post_meta( $data['id'], RequestPostType::META_FLAGGED_AS_SPAM, true ) );
	}

	public function test_invalid_fields_are_refused_with_the_reason(): void {
		wp_set_current_user( self::factory()->user->create() );
		$cases = [
			'title'        => [ 'title' => '   ' ],
			'title-long'   => [ 'title' => str_repeat( 'a', SubmissionService::MAX_TITLE + 1 ) ],
			'details-long' => [ 'description' => str_repeat( 'a', SubmissionService::MAX_DESCRIPTION + 1 ) ],
			'category'     => [ 'category' => 999999 ],
		];

		foreach ( $cases as $reason => $fields ) {
			$response = $this->submit( $fields );
			$this->assertSame( 400, $response->get_status(), $reason );
			$this->assertSame( SubmissionService::ERROR_INVALID, $response->get_data()['code'], $reason );
			$this->assertSame( $reason, $response->get_data()['data']['reason'], $reason );
		}
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_guest_names_and_emails_are_checked(): void {
		$this->allow_guests();
		$cases = [
			'name-long' => [ 'name' => str_repeat( 'a', SubmissionService::MAX_NAME + 1 ) ],
			'email'     => [ 'email' => 'not-an-email' ],
		];

		foreach ( $cases as $reason => $fields ) {
			$response = $this->submit( $fields, $this->same_site() );
			$this->assertSame( 400, $response->get_status(), $reason );
			$this->assertSame( $reason, $response->get_data()['data']['reason'], $reason );
		}
	}

	public function test_logged_in_users_cannot_attach_a_different_name_or_email(): void {
		wp_set_current_user( self::factory()->user->create() );

		$request = (int) $this->submit(
			[
				'name'  => 'Someone Else',
				'email' => 'someone@example.com',
			]
		)->get_data()['id'];

		$this->assertSame( '', get_post_meta( $request, RequestPostType::META_SUBMITTER_NAME, true ) );
		$this->assertSame( '', get_post_meta( $request, RequestPostType::META_SUBMITTER_EMAIL, true ) );
	}

	public function test_text_is_saved_as_plain_text_and_shortcodes_in_it_do_not_run(): void {
		add_shortcode(
			'frs_probe',
			static function () {
				return 'PROBE RAN';
			}
		);
		wp_set_current_user( self::factory()->user->create() );

		$request = get_post(
			$this->submit(
				[
					'title'       => '<b>Bold</b> idea',
					'description' => "<script>alert(1)</script>Keep C:\\Users\\me and [frs_probe] as typed.",
				]
			)->get_data()['id']
		);
		$rendered = apply_filters( 'the_content', $request->post_content );
		remove_shortcode( 'frs_probe' );

		$this->assertSame( 'Bold idea', $request->post_title );
		$this->assertStringNotContainsString( 'script', $request->post_content );
		$this->assertStringNotContainsString( 'alert', $request->post_content );
		$this->assertStringContainsString( 'C:\\Users\\me', $request->post_content, 'Backslashes survive saving.' );
		$this->assertStringNotContainsString( 'PROBE RAN', $rendered );
		$this->assertStringNotContainsString( '[frs_probe]', $rendered );
		$this->assertStringContainsString( '[frs_probe]', html_entity_decode( $rendered ), 'The brackets display as typed.' );
	}

	public function test_too_many_submissions_return_429_with_retry_after(): void {
		add_filter(
			RateLimiter::FILTER_LIMIT,
			static function () {
				return 2;
			}
		);
		wp_set_current_user( self::factory()->user->create() );

		$this->submit();
		$this->submit();
		$response = $this->submit();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( SubmissionService::ERROR_RATE_LIMITED, $response->get_data()['code'] );
		$this->assertGreaterThan( 0, (int) $response->get_headers()['Retry-After'] );
		$this->assertSame( 2, $this->count_requests() );
	}

	/**
	 * Sends a submission through the REST server.
	 *
	 * @param array<string, mixed>  $fields   Fields to override in a valid submission.
	 * @param array<string, string> $headers  Request headers.
	 * @param int                   $board_id Board term ID. Defaults to the default board.
	 */
	private function submit( array $fields = [], array $headers = [], int $board_id = 0 ): WP_REST_Response {
		$board   = $board_id ? $board_id : $this->default_board();
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/boards/' . $board . '/requests' );
		$request->set_body_params( $this->submission( $fields ) );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Headers a browser sends with a form on this site.
	 *
	 * @return array<string, string>
	 */
	private function same_site(): array {
		return [ 'Origin' => home_url() ];
	}

	/**
	 * Lets guests submit to the default board.
	 */
	private function allow_guests(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
	}

	/**
	 * Counts requests in any status.
	 */
	private function count_requests(): int {
		return count(
			get_posts(
				[
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}
}
