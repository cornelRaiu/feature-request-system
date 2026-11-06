<?php
/**
 * Tests of the link that confirms a visitor's email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Guests\ConfirmationLink;
use FeatureRequestSystem\Guests\Guests;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Rest\VotesController;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;

/**
 * A visitor on a board where visitors vote once they confirm their email: from the vote
 * that asks for their address, through the emailed link, to the vote that counts.
 */
final class GuestLinkTest extends TestCase {

	use GuestHelpers;

	/**
	 * A request on the default board.
	 *
	 * @var int
	 */
	private $request = 0;

	public function set_up(): void {
		parent::set_up();
		$this->forget_visitor();
		$this->board_rules( BoardSettings::VERIFIED );
		$this->request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
	}

	public function tear_down(): void {
		$this->forget_visitor();
		parent::tear_down();
	}

	public function test_a_visitor_who_has_not_confirmed_an_email_is_asked_for_one_and_nothing_is_stored(): void {
		$response = $this->rest_vote( 'POST', $this->request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( VoteService::ERROR_EMAIL_REQUIRED, $response->get_data()['code'] );
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );
		$this->assertArrayNotHasKey( Guests::COOKIE, $_COOKIE );
		$this->assertArrayNotHasKey( VoterIdentity::COOKIE, $_COOKIE );
	}

	public function test_logged_in_users_vote_on_such_a_board_without_an_email(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( 200, $this->rest_vote( 'POST', $this->request )->get_status() );
	}

	public function test_asking_for_a_link_emails_it_and_stores_only_a_hash_of_its_secret(): void {
		global $wpdb;

		$response = $this->ask_for_link( 'Jane@Example.com', $this->request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'message' => 'Check your email. Open the link we sent you within 24 hours and press the button on its page: then your vote counts.' ], $response->get_data() );

		$emails = $this->sent_emails();
		$this->assertCount( 1, $emails );
		$this->assertSame( 'jane@example.com', $emails[0]['to'][0][0] );
		$this->assertStringContainsString( 'Confirm your email address', $emails[0]['subject'] );
		$this->assertStringContainsString( 'Your vote for "Dark mode" is added.', $emails[0]['body'] );
		$this->assertStringContainsString( 'within 24 hours', $emails[0]['body'] );

		$token = $this->emailed_token();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
		$this->assertStringContainsString( ConfirmationLink::url( $token ), $emails[0]['body'] );

		$guest = $this->guest( 'jane@example.com' );
		$this->assertSame( hash( 'sha256', $token ), $guest['token_hash'] );
		$this->assertSame( '', $guest['confirmed_at'] );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( Schema::GUESTS ), $guest['id'] ), ARRAY_A );
		$this->assertStringNotContainsString( $token, implode( '|', $row ), 'The secret itself is only in the email.' );

		$this->assertSame( [], $this->vote_rows_of( $this->request ), 'The vote waits for the confirmation.' );
		$this->assertArrayNotHasKey( Guests::COOKIE, $_COOKIE );
	}

	public function test_the_answer_is_the_same_whether_or_not_the_address_is_known(): void {
		$new = $this->ask_for_link( 'new@example.com', $this->request );

		$this->ask_for_link( 'waiting@example.com', $this->request );
		$waiting = $this->ask_for_link( 'waiting@example.com', $this->request );

		$this->become_guest( 'known@example.com' );
		unset( $_COOKIE[ Guests::COOKIE ] );
		$known = $this->ask_for_link( 'known@example.com', $this->request );

		foreach ( [ $waiting, $known ] as $response ) {
			$this->assertSame( $new->get_status(), $response->get_status() );
			$this->assertSame( $new->get_data(), $response->get_data() );
			$this->assertSame( $new->get_headers(), $response->get_headers() );
		}
	}

	public function test_an_address_gets_one_link_every_ten_minutes_and_a_newer_link_replaces_the_older(): void {
		$other = $this->create_request( [ 'post_title' => 'Calendar view' ] );

		$this->ask_for_link( 'jane@example.com', $this->request );
		$first = $this->emailed_token();
		$this->ask_for_link( 'jane@example.com', $other );

		$this->assertCount( 1, $this->sent_emails(), 'No second email within ten minutes.' );
		$this->assertCount( 2, $this->guest( 'jane@example.com' )['pending'], 'The second vote waits for the first link.' );

		$this->age_link( $this->guest( 'jane@example.com' )['id'], 9 * MINUTE_IN_SECONDS );
		$this->ask_for_link( 'jane@example.com', $other );
		$this->assertCount( 1, $this->sent_emails() );

		$this->age_link( $this->guest( 'jane@example.com' )['id'], 10 * MINUTE_IN_SECONDS );
		$this->ask_for_link( 'jane@example.com', $other );
		$this->assertCount( 2, $this->sent_emails() );
		$second = $this->emailed_token();
		$this->assertNotSame( $first, $second );

		$this->assertStringContainsString( '<h1>This link no longer works</h1>', $this->open_link( $first ) );
		$this->assertStringContainsString( '<h1>Confirm your email address</h1>', $this->open_link( $second ) );

		// Both votes wait, the same vote once.
		$this->open_link( $second, true );
		$this->assertCount( 1, $this->vote_rows_of( $this->request ) );
		$this->assertCount( 1, $this->vote_rows_of( $other ) );
	}

	public function test_one_ip_address_cannot_ask_for_more_than_ten_links_an_hour(): void {
		$statuses = [];
		foreach ( range( 1, 11 ) as $i ) {
			$statuses[] = $this->ask_for_link( "visitor{$i}@example.com", $this->request )->get_status();
		}

		$this->assertSame( array_merge( array_fill( 0, 10, 200 ), [ 429 ] ), $statuses );
		$this->assertCount( 10, $this->sent_emails() );
		$response = $this->ask_for_link( 'one-more@example.com', $this->request );
		$this->assertSame( Guests::ERROR_RATE_LIMITED, $response->get_data()['code'] );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );
	}

	public function test_opening_the_link_only_asks_so_a_program_that_scans_links_confirms_nobody(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );

		$page = $this->open_link( $this->emailed_token() );

		$this->assertStringContainsString( '<h1>Confirm your email address</h1>', $page );
		$this->assertStringContainsString( 'jane@example.com is your email address', $page );
		$this->assertStringContainsString( 'Your vote for &quot;Dark mode&quot; is added.', $page );
		$this->assertStringContainsString( '<button type="submit"', $page );
		$this->assertSame( '', $this->guest( 'jane@example.com' )['confirmed_at'] );
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );
		$this->assertArrayNotHasKey( Guests::COOKIE, $_COOKIE );
	}

	public function test_pressing_the_button_confirms_the_address_counts_the_vote_and_remembers_the_guest(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );

		$page  = $this->open_link( $this->emailed_token(), true );
		$guest = $this->guest( 'jane@example.com' );

		$this->assertStringContainsString( '<h1>Your email address is confirmed</h1>', $page );
		$this->assertStringContainsString( 'Your vote for &quot;Dark mode&quot; was added.', $page );
		$this->assertStringContainsString( 'href="' . get_permalink( $this->request ) . '" rel="noreferrer"', $page );
		$this->assertNotSame( '', $guest['confirmed_at'] );
		$this->assertSame( [], $guest['pending'] );
		$this->assertSame( '', $guest['token_hash'] );

		$rows = $this->vote_rows_of( $this->request );
		$this->assertCount( 1, $rows );
		$this->assertSame( '0', $rows[0]->user_id );
		$this->assertSame( hash( 'sha256', 'e:' . $guest['id'] ), $rows[0]->voter_key );
		$this->assertSame( 1, (int) get_post_meta( $this->request, '_mwtfrs_votes', true ) );

		$this->assertMatchesRegularExpression( '/^' . $guest['id'] . '\.[a-f0-9]{64}$/', $_COOKIE[ Guests::COOKIE ] );
		$this->assertSame( $guest['id'], Guests::current_id() );

		// From now on the guest votes straight away.
		$other = $this->create_request();
		$this->assertSame( 200, $this->rest_vote( 'POST', $other )->get_status() );
	}

	public function test_a_link_works_once(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );
		$token = $this->emailed_token();
		$this->open_link( $token, true );
		unset( $_COOKIE[ Guests::COOKIE ] );

		$this->assertStringContainsString( '<h1>This link no longer works</h1>', $this->open_link( $token, true ) );
		$this->assertArrayNotHasKey( Guests::COOKIE, $_COOKIE );
		$this->assertCount( 1, $this->vote_rows_of( $this->request ) );
	}

	public function test_a_link_expires_after_a_day(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );
		$token = $this->emailed_token();
		$guest = $this->guest( 'jane@example.com' );

		$this->age_link( $guest['id'], DAY_IN_SECONDS - MINUTE_IN_SECONDS );
		$this->assertStringContainsString( '<h1>Confirm your email address</h1>', $this->open_link( $token ) );

		$this->age_link( $guest['id'], DAY_IN_SECONDS + MINUTE_IN_SECONDS );
		$this->assertStringContainsString( '<h1>This link has expired</h1>', $this->open_link( $token, true ) );
		$this->assertSame( '', $this->guest( 'jane@example.com' )['confirmed_at'] );
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );

		// A new link starts afresh: what waited for the expired one is forgotten.
		$other = $this->create_request();
		$this->ask_for_link( 'jane@example.com', $other );
		$this->open_link( $this->emailed_token(), true );
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );
		$this->assertCount( 1, $this->vote_rows_of( $other ) );
	}

	public function test_what_waited_for_an_expired_link_is_kept_no_longer_than_needed(): void {
		$this->ask_for_link( 'never@example.com', $this->request );
		$this->become_guest( 'jane@example.com' );
		unset( $_COOKIE[ Guests::COOKIE ] );
		$this->ask_for_link( 'jane@example.com', $this->request );
		$this->ask_for_link( 'recent@example.com', $this->request );

		$this->age_link( $this->guest( 'never@example.com' )['id'], DAY_IN_SECONDS + MINUTE_IN_SECONDS );
		$this->age_link( $this->guest( 'jane@example.com' )['id'], DAY_IN_SECONDS + MINUTE_IN_SECONDS );
		$this->ask_for_link( 'someone@example.com', $this->request );

		$this->assertNull( $this->guest( 'never@example.com' ), 'An address that was never confirmed is forgotten.' );
		$this->assertSame( [], $this->guest( 'jane@example.com' )['pending'], 'A confirmed guest keeps the address only.' );
		$this->assertSame( '', $this->guest( 'jane@example.com' )['token_hash'] );
		$this->assertCount( 1, $this->guest( 'recent@example.com' )['pending'], 'A link that still works keeps what waits for it.' );
	}

	public function test_an_address_never_confirmed_is_forgotten_once_its_link_expires_even_if_nobody_asks_again(): void {
		$this->ask_for_link( 'never@example.com', $this->request );
		$guest = $this->guest( 'never@example.com' );

		$actions = $this->waiting_actions( Guests::HOOK_FORGET );
		$this->assertCount( 1, $actions, 'Sending the link schedules the cleanup.' );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS, reset( $actions )->get_schedule()->get_date()->getTimestamp() );

		$this->age_link( $guest['id'], DAY_IN_SECONDS + MINUTE_IN_SECONDS );
		$this->run_waiting_actions( Guests::HOOK_FORGET );

		$this->assertNull( $this->guest( 'never@example.com' ) );
	}

	public function test_a_button_pressed_on_another_site_confirms_nothing(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );

		$page = $this->open_link( $this->emailed_token(), true, 'https://evil.example' );

		$this->assertStringContainsString( '<h1>Confirm your email address</h1>', $page );
		$this->assertSame( '', $this->guest( 'jane@example.com' )['confirmed_at'] );
		$this->assertArrayNotHasKey( Guests::COOKIE, $_COOKIE );
	}

	public function test_a_wrong_token_and_other_visits_are_left_alone(): void {
		ConfirmationLink::handle();
		$this->assertArrayNotHasKey( Guests::COOKIE, $_COOKIE, 'A visit without the parameter is none of its business.' );

		$this->assertStringContainsString( '<h1>This link no longer works</h1>', $this->open_link( str_repeat( 'a', 32 ), true ) );
		$this->assertStringContainsString( '<h1>This link no longer works</h1>', $this->open_link( '', true ) );
	}

	public function test_the_spam_checks_and_the_origin_apply_to_asking_for_a_link(): void {
		$this->assertSame( SpamGuard::ERROR_REJECTED, $this->ask_for_link( 'jane@example.com', $this->request, [ SpamGuard::FIELD_TRAP => 'http://spam.example' ] )->get_data()['code'] );
		$this->assertSame( SpamGuard::ERROR_REJECTED, $this->ask_for_link( 'jane@example.com', $this->request, [ SpamGuard::FIELD_TOKEN => SpamGuard::token() ] )->get_data()['code'], 'Sent the moment the page loaded.' );
		$this->assertSame( VotesController::ERROR_INVALID_ORIGIN, $this->ask_for_link( 'jane@example.com', $this->request, [], [ 'Origin' => 'https://evil.example' ] )->get_data()['code'] );
		$this->assertSame( Guests::ERROR_INVALID_EMAIL, $this->ask_for_link( 'not-an-email', $this->request )->get_data()['code'] );
		$this->assertSame( 400, $this->ask_for_link( str_repeat( 'a', 250 ) . '@example.com', $this->request )->get_status(), 'Longer than any working address.' );

		$this->assertSame( [], $this->sent_emails() );
		$this->assertNull( $this->guest( 'jane@example.com' ) );
	}

	public function test_a_captcha_plugin_can_refuse_a_link(): void {
		add_filter(
			SpamGuard::FILTER_CAPTCHA,
			static function () {
				return false;
			}
		);

		$this->assertSame( SpamGuard::ERROR_REJECTED, $this->ask_for_link( 'jane@example.com', $this->request )->get_data()['code'] );
		$this->assertSame( [], $this->sent_emails() );
	}

	public function test_no_link_for_a_request_that_takes_no_votes(): void {
		wp_set_object_terms( $this->request, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		$this->assertSame( VoteService::ERROR_CLOSED, $this->ask_for_link( 'jane@example.com', $this->request )->get_data()['code'] );

		$this->assertSame( VoteService::ERROR_NOT_FOUND, $this->ask_for_link( 'jane@example.com', $this->create_request( [ 'post_status' => 'pending' ] ) )->get_data()['code'] );

		$this->board_rules( BoardSettings::LOGGED_IN );
		$this->assertSame( VoteService::ERROR_LOGIN_REQUIRED, $this->ask_for_link( 'jane@example.com', $this->create_request() )->get_data()['code'] );

		// A board that takes every visitor's vote needs no address, so nobody is emailed for it.
		$this->board_rules( BoardSettings::ANYONE );
		$response = $this->ask_for_link( 'jane@example.com', $this->create_request() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Guests::ERROR_NOT_NEEDED, $response->get_data()['code'] );

		$this->assertSame( [], $this->sent_emails() );
		$this->assertNull( $this->guest( 'jane@example.com' ) );
	}

	public function test_pressing_the_button_while_logged_in_counts_the_vote_as_the_guest_who_started_it(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );
		$token = $this->emailed_token();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$page  = $this->open_link( $token, true );
		$guest = $this->guest( 'jane@example.com' );

		$this->assertStringContainsString( 'Your vote for &quot;Dark mode&quot; was added.', $page );
		$this->assertEquals(
			[
				(object) [
					'user_id'   => '0',
					'voter_key' => VoterIdentity::guest_key( $guest['id'] ),
				],
			],
			$this->vote_rows_of( $this->request ),
			'The vote is the guest\'s, not the account that happened to be logged in.'
		);
		$this->assertNotSame( 0, get_current_user_id(), 'The visitor stays logged in.' );
	}

	public function test_a_vote_that_can_no_longer_be_cast_is_explained_on_the_page(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );
		wp_set_object_terms( $this->request, $this->status( 'shipped' ), StatusTaxonomy::NAME );

		$page = $this->open_link( $this->emailed_token(), true );

		$this->assertStringContainsString( '<h1>Your email address is confirmed</h1>', $page );
		$this->assertStringContainsString( 'Your vote for &quot;Dark mode&quot; could not be added. Voting is closed because this request is Shipped.', $page );
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );
	}

	public function test_the_link_page_names_no_request_that_is_not_public(): void {
		$this->ask_for_link( 'jane@example.com', $this->request );
		wp_update_post(
			[
				'ID'          => $this->request,
				'post_status' => 'draft',
			]
		);

		$page = $this->open_link( $this->emailed_token() );

		$this->assertStringContainsString( 'Your vote is added.', $page );
		$this->assertStringNotContainsString( 'Dark mode', $page );
	}
}
