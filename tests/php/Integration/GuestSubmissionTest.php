<?php
/**
 * Tests of feature requests from visitors who confirm their email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\SubmissionService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * On a board where visitors suggest features once they confirm their email, a visitor's
 * request waits for the button behind the emailed link.
 */
final class GuestSubmissionTest extends TestCase {

	use GuestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->forget_visitor();
		$this->board_rules( BoardSettings::LOGGED_IN, BoardSettings::VERIFIED );
		Settings::update( Settings::NOTIFY_EMAILS, 'team@example.com' );
	}

	public function tear_down(): void {
		$this->forget_visitor();
		parent::tear_down();
	}

	public function test_a_visitors_request_waits_for_the_confirmation_and_then_reaches_the_moderators(): void {
		$response = $this->submit_as_visitor();

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame(
			[
				'id'      => 0,
				'status'  => SubmissionService::STATUS_UNCONFIRMED,
				'url'     => '',
				'message' => 'Check your email. Open the link we sent you within 24 hours and press the button on its page: then your request is sent.',
			],
			$response->get_data()
		);
		$this->assertSame( 0, $this->count_requests() );
		$this->assertSame( [ 'jane@example.com' ], $this->emailed(), 'The link only. The moderators hear of it once it is sent.' );
		$this->assertStringContainsString( 'Your feature request is sent.', $this->sent_emails()[0]['body'] );
		$this->assertStringNotContainsString( 'Export to CSV', $this->sent_emails()[0]['body'], 'The email carries nobody\'s text to the address.' );

		$token = $this->emailed_token();
		$this->assertStringContainsString( 'Your feature request &quot;Export to CSV&quot; is sent.', $this->open_link( $token ) );
		$this->assertSame( 0, $this->count_requests(), 'Opening the link sends nothing.' );

		$page    = $this->open_link( $token, true );
		$request = $this->only_request();
		$guest   = $this->guest( 'jane@example.com' );

		$this->assertStringContainsString( 'Your feature request &quot;Export to CSV&quot; was sent. It will appear on the board once it is approved.', $page );
		$this->assertSame( 'pending', $request->post_status );
		$this->assertSame( 'For our accountant.', $request->post_content );
		$this->assertSame( 'Jane', get_post_meta( $request->ID, RequestPostType::META_SUBMITTER_NAME, true ) );
		$this->assertSame( 'jane@example.com', get_post_meta( $request->ID, RequestPostType::META_SUBMITTER_EMAIL, true ) );
		$this->assertSame( (string) $guest['id'], get_post_meta( $request->ID, RequestPostType::META_SUBMITTER_GUEST, true ) );
		$this->assertSame( 'Jane', $guest['name'], 'The name given with the request is the guest\'s once confirmed.' );
		$this->assertSame( SubscriptionRepository::SOURCE_AUTHOR, SubscriptionRepository::find_guest( $request->ID, $guest['id'] )['source'] );
		$this->assertSame( [ 'jane@example.com', 'team@example.com' ], $this->emailed() );
	}

	public function test_a_request_to_a_board_without_moderation_is_on_the_board_once_confirmed(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::MODERATION => false ] );
		$this->submit_as_visitor();

		$page    = $this->open_link( $this->emailed_token(), true );
		$request = $this->only_request();

		$this->assertSame( 'publish', $request->post_status );
		$this->assertStringContainsString( 'Your feature request &quot;Export to CSV&quot; is on the board.', $page );
		$this->assertStringContainsString( 'href="' . get_permalink( $request ) . '" rel="noreferrer">Go to your request', $page );
	}

	public function test_a_confirmed_guest_who_gives_their_address_sends_the_request_straight_away(): void {
		$guest = $this->become_guest( 'jane@example.com' );

		$response = $this->submit_as_visitor( [ 'email' => 'JANE@example.com' ] );

		$this->assertSame( 201, $response->get_status() );
		$request = $this->only_request();
		$this->assertSame( (string) $guest, get_post_meta( $request->ID, RequestPostType::META_SUBMITTER_GUEST, true ) );
		$this->assertNotNull( SubscriptionRepository::find_guest( $request->ID, $guest ) );
		$this->assertSame( [ 'team@example.com' ], $this->emailed() );
	}

	public function test_a_confirmed_guest_who_gives_another_address_confirms_that_one_first(): void {
		$this->become_guest( 'jane@example.com' );

		$response = $this->submit_as_visitor( [ 'email' => 'jane.work@example.com' ] );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 0, $this->count_requests() );
		$this->assertSame( [ 'jane.work@example.com' ], $this->emailed() );
	}

	public function test_the_name_and_the_address_are_needed(): void {
		$this->assertSame( 'name', $this->submit_as_visitor( [ 'name' => '' ] )->get_data()['data']['reason'] );
		$this->assertSame( 'no-email', $this->submit_as_visitor( [ 'email' => '' ] )->get_data()['data']['reason'] );
		$this->assertSame( 'no-email', $this->submit_as_visitor( [ 'email' => 'not-an-email' ] )->get_data()['data']['reason'] );
		$this->assertSame( 'Enter your email address. We will email you a link to confirm it.', $this->submit_as_visitor( [ 'email' => '' ] )->get_data()['message'] );
		$this->assertSame( [], $this->sent_emails() );
	}

	public function test_on_other_boards_a_visitor_still_needs_neither(): void {
		$this->board_rules( BoardSettings::LOGGED_IN, BoardSettings::ANYONE );

		$response = $this->submit_as_visitor(
			[
				'name'  => '',
				'email' => '',
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( [ 'team@example.com' ], $this->emailed() );
	}

	public function test_logged_in_users_send_their_requests_straight_away(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( 201, $this->submit_as_visitor( [ 'email' => '' ] )->get_status() );
	}

	public function test_the_spam_checks_run_before_anything_is_emailed(): void {
		$this->assertSame( SpamGuard::ERROR_REJECTED, $this->submit_as_visitor( [ SpamGuard::FIELD_TRAP => 'spam' ] )->get_data()['code'] );
		$this->assertSame( [], $this->sent_emails() );
	}

	public function test_a_request_flagged_as_spam_stays_flagged_once_confirmed(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::MODERATION => false ] );
		add_filter( SpamGuard::FILTER_SPAM, '__return_true' );
		$this->submit_as_visitor();
		remove_filter( SpamGuard::FILTER_SPAM, '__return_true' );

		$this->open_link( $this->emailed_token(), true );
		$request = $this->only_request();

		$this->assertSame( 'pending', $request->post_status );
		$this->assertSame( '1', get_post_meta( $request->ID, RequestPostType::META_FLAGGED_AS_SPAM, true ) );
	}

	public function test_a_board_that_stopped_taking_requests_meanwhile_gets_none(): void {
		$this->submit_as_visitor();
		$this->board_rules( BoardSettings::LOGGED_IN, BoardSettings::NOBODY );

		$page = $this->open_link( $this->emailed_token(), true );

		$this->assertStringContainsString( 'Your feature request &quot;Export to CSV&quot; could not be sent. This board is not taking new requests.', $page );
		$this->assertSame( 0, $this->count_requests() );
		$this->assertNotSame( '', $this->guest( 'jane@example.com' )['confirmed_at'], 'The address is confirmed all the same.' );
	}

	public function test_without_javascript_the_form_says_to_check_the_email(): void {
		$input = array_merge(
			$this->submission(
				[
					'name'  => 'Jane',
					'email' => 'jane@example.com',
				]
			),
			[
				'board'       => $this->default_board(),
				'redirect_to' => home_url( '/suggest/' ),
			]
		);

		$url = SubmitForm::process( $input, home_url(), '' );

		$this->assertStringContainsString( SubmitForm::PARAM_NOTICE . '=confirm', $url );
		$_GET[ SubmitForm::PARAM_NOTICE ] = 'confirm';
		$this->assertSame(
			[
				'message' => 'Check your email. Open the link we sent you within 24 hours and press the button on its page: then your request is sent.',
				'error'   => false,
				'login'   => false,
			],
			SubmitForm::notice_from_request()
		);

		$this->assertStringContainsString( SubmitForm::PARAM_NOTICE . '=invalid-name', SubmitForm::process( array_merge( $input, [ 'name' => '' ] ), home_url(), '' ) );
		$this->assertStringContainsString( SubmitForm::PARAM_NOTICE . '=invalid-no-email', SubmitForm::process( array_merge( $input, [ 'email' => '' ] ), home_url(), '' ) );
	}

	public function test_the_form_asks_for_the_name_and_the_address_and_is_the_same_for_every_visitor(): void {
		$stranger = $this->render_form();

		$this->assertMatchesRegularExpression( '/name="name"[^>]*required/', $stranger );
		$this->assertMatchesRegularExpression( '/name="email"[^>]*required/', $stranger );
		$this->assertStringContainsString( 'We email you a link to confirm your address, and send your request once you do.', $stranger );

		$this->become_guest( 'jane@example.com' );
		$guest = $this->render_form();
		$this->assertSame( $this->normalize( $stranger ), $this->normalize( $guest ) );

		$this->board_rules( BoardSettings::LOGGED_IN, BoardSettings::ANYONE );
		$this->assertDoesNotMatchRegularExpression( '/name="email"[^>]*required/', $this->render_form() );
	}

	/**
	 * Submits through the REST API, from a page of this site, as a visitor of a board that asks for the email.
	 *
	 * @param array<string, mixed> $fields Fields to override.
	 */
	private function submit_as_visitor( array $fields = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/boards/' . $this->default_board() . '/requests' );
		$request->set_header( 'Origin', home_url() );
		$request->set_body_params(
			$this->submission(
				array_merge(
					[
						'name'  => 'Jane',
						'email' => 'jane@example.com',
					],
					$fields
				)
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * Renders the form block.
	 */
	private function render_form(): string {
		return render_block(
			[
				'blockName'    => SubmitForm::BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Removes what legitimately differs between two renders: generated IDs, and the time in the spam check token.
	 *
	 * @param string $html Form HTML.
	 */
	private function normalize( string $html ): string {
		$html = (string) preg_replace( '/mwtfrs-submit-\d+/', 'mwtfrs-submit-N', $html );

		return (string) preg_replace( '/name="' . SpamGuard::FIELD_TOKEN . '" value="[^"]*"/', 'name="token" value="N"', $html );
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

	/**
	 * Returns the one request there is.
	 */
	private function only_request(): \WP_Post {
		$requests = get_posts(
			[
				'post_type'   => RequestPostType::NAME,
				'post_status' => 'any',
				'numberposts' => -1,
			]
		);
		$this->assertCount( 1, $requests );

		return $requests[0];
	}
}
