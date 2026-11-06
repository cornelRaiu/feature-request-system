<?php
/**
 * Tests of votes from visitors who confirmed their email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\GuestLinkForm;
use FeatureRequestSystem\Frontend\RequestNotice;
use FeatureRequestSystem\Frontend\RequestView;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Guests\GuestRepository;
use FeatureRequestSystem\Guests\Guests;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use WP_REST_Request;

/**
 * A guest who confirmed their email votes like a user: once per request, followed by
 * the emails about it. Pages stay the same for every visitor.
 */
final class GuestVotesTest extends TestCase {

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

	public function test_a_confirmed_guest_votes_once_and_follows_the_request(): void {
		$guest = $this->become_guest();

		$first  = $this->rest_vote( 'POST', $this->request );
		$second = $this->rest_vote( 'POST', $this->request );

		$this->assertSame( [ 'votes' => 1, 'voted' => true ], $first->get_data(), 'Only users are told whether they follow: a guest has no Follow button.' );
		$this->assertSame( [ 'votes' => 1, 'voted' => true ], $second->get_data() );
		$rows = $this->vote_rows_of( $this->request );
		$this->assertCount( 1, $rows );
		$this->assertSame( hash( 'sha256', 'e:' . $guest ), $rows[0]->voter_key );

		$subscription = SubscriptionRepository::find_guest( $this->request, $guest );
		$this->assertSame( SubscriptionRepository::SOURCE_VOTE, $subscription['source'] );
		$this->assertSame( 0, $subscription['user_id'] );
	}

	public function test_taking_the_vote_back_ends_the_following_that_came_with_it(): void {
		$guest = $this->become_guest();
		$this->rest_vote( 'POST', $this->request );

		$this->assertSame( [ 'votes' => 0, 'voted' => false ], $this->rest_vote( 'DELETE', $this->request )->get_data() );
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );
		$this->assertNull( SubscriptionRepository::find_guest( $this->request, $guest ) );
	}

	public function test_two_guests_follow_the_same_request_with_users(): void {
		$user = self::factory()->user->create();
		SubscriptionRepository::add( $this->request, $user, SubscriptionRepository::SOURCE_FOLLOW );

		$jane = $this->become_guest( 'jane@example.com' );
		$this->rest_vote( 'POST', $this->request );
		$john = $this->become_guest( 'john@example.com' );
		$this->rest_vote( 'POST', $this->request );

		$this->assertSame( 3, SubscriptionRepository::count( $this->request ) );
		$this->assertNotNull( SubscriptionRepository::find_guest( $this->request, $jane ) );
		$this->assertNotNull( SubscriptionRepository::find_guest( $this->request, $john ) );
		$this->assertSame( 2, (int) get_post_meta( $this->request, '_mwtfrs_votes', true ) );
	}

	public function test_a_guest_who_stopped_every_email_or_a_site_without_them_votes_without_following(): void {
		$guest = $this->become_guest();
		GuestRepository::set_auto_follow( $guest, false );
		$this->rest_vote( 'POST', $this->request );
		$this->assertNull( SubscriptionRepository::find_guest( $this->request, $guest ) );

		GuestRepository::set_auto_follow( $guest, true );
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$other = $this->create_request();
		$this->rest_vote( 'POST', $other );
		$this->assertNull( SubscriptionRepository::find_guest( $other, $guest ) );
		$this->assertCount( 1, $this->vote_rows_of( $other ) );
	}

	public function test_the_page_learns_a_guests_votes_after_it_loads(): void {
		$this->become_guest();
		$other = $this->create_request();
		$this->rest_vote( 'POST', $this->request );

		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/me/state' );
		$request->set_body_params( [ 'ids' => [ $this->request, $other ] ] );

		$this->assertSame( [ 'voted' => [ $this->request ] ], rest_do_request( $request )->get_data() );
	}

	public function test_a_guest_on_a_board_open_to_everyone_votes_as_the_confirmed_guest(): void {
		$this->board_rules( BoardSettings::ANYONE );
		$guest = $this->become_guest();

		$this->rest_vote( 'POST', $this->request );

		$this->assertSame( hash( 'sha256', 'e:' . $guest ), $this->vote_rows_of( $this->request )[0]->voter_key );
		$this->assertArrayNotHasKey( VoterIdentity::COOKIE, $_COOKIE, 'No second identity is made up.' );
	}

	public function test_a_forged_or_tampered_cookie_is_nobody(): void {
		$guest = $this->become_guest();
		$valid = $_COOKIE[ Guests::COOKIE ];

		foreach ( [ $guest . '.' . str_repeat( 'a', 64 ), ( $guest + 1 ) . substr( $valid, strlen( (string) $guest ) ), 'garbage', '' ] as $cookie ) {
			$_COOKIE[ Guests::COOKIE ] = $cookie;
			$this->assertSame( 0, Guests::current_id(), $cookie );
			$this->assertSame( VoteService::ERROR_EMAIL_REQUIRED, $this->rest_vote( 'POST', $this->request )->get_data()['code'], $cookie );
		}
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );
	}

	public function test_an_address_that_waits_for_confirmation_is_nobody(): void {
		global $wpdb;

		$guest = $this->become_guest( 'jane@example.com' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET confirmed_at = NULL WHERE id = %d', Schema::table( Schema::GUESTS ), $guest ) );

		$this->assertSame( 0, Guests::current_id() );
	}

	public function test_a_cookie_stops_working_when_its_guest_is_gone_even_if_a_new_guest_gets_the_id(): void {
		global $wpdb;

		$guest  = $this->become_guest( 'jane@example.com' );
		$cookie = $_COOKIE[ Guests::COOKIE ];
		GuestRepository::delete( $guest );
		$this->assertSame( 0, Guests::current_id() );

		// Some databases give the ID of a deleted row to the next one.
		$wpdb->insert(
			Schema::table( Schema::GUESTS ),
			[
				'id'           => $guest,
				'email'        => 'mallory@example.com',
				'confirmed_at' => gmdate( 'Y-m-d H:i:s' ),
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() + 5 ),
			]
		);
		$_COOKIE[ Guests::COOKIE ] = $cookie;
		$this->assertSame( 0, Guests::current_id() );
	}

	public function test_logged_in_users_are_never_guests(): void {
		$this->become_guest();
		wp_set_current_user( self::factory()->user->create() );

		$this->assertNull( Guests::current() );
		$this->rest_vote( 'POST', $this->request );
		$this->assertNotSame( '0', $this->vote_rows_of( $this->request )[0]->user_id );
	}

	public function test_sign_in_confirms_an_address_without_a_link_for_other_plugins(): void {
		$guest = Guests::sign_in( ' Jane@Example.com ', 'Jane Doe' );

		$stored = $this->guest( 'jane@example.com' );
		$this->assertSame( $stored['id'], $guest );
		$this->assertSame( 'Jane Doe', $stored['name'] );
		$this->assertNotSame( '', $stored['confirmed_at'] );
		$this->assertSame( $guest, Guests::current_id() );
		$this->assertSame( [], $this->sent_emails() );

		$this->assertSame( $guest, Guests::sign_in( 'jane@example.com', '' ), 'The same address is the same guest.' );
		$this->assertSame( 'Jane Doe', $this->guest( 'jane@example.com' )['name'], 'An empty name keeps the one stored.' );
		$this->assertSame( 0, Guests::sign_in( 'not-an-email', 'Jane' ) );
	}

	public function test_without_javascript_a_vote_asks_for_the_email_next_to_the_request(): void {
		$url = VoteForm::process(
			[
				'request_id'  => $this->request,
				'redirect_to' => home_url( '/board/' ),
			],
			home_url(),
			''
		);

		$this->assertStringContainsString( RequestNotice::PARAM . '=email', $url );
		$this->assertSame( [], $this->vote_rows_of( $this->request ) );

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
		$html = $this->render_board();
		$this->assertStringContainsString( 'Confirm your email address to vote. We will email you a link.', $html );
		$this->assertDoesNotMatchRegularExpression( '/\shidden[\s>]/', $this->email_form_tag( $html ), 'The form is shown.' );
	}

	public function test_without_javascript_the_email_form_sends_the_link_and_says_so(): void {
		$input = [
			'request_id'           => $this->request,
			'redirect_to'          => home_url( '/board/' ),
			'email'                => 'jane@example.com',
			SpamGuard::FIELD_TRAP  => '',
			SpamGuard::FIELD_TOKEN => SpamGuard::token( time() - 30 ),
		];

		$this->assertStringContainsString( RequestNotice::PARAM . '=origin', GuestLinkForm::process( $input, 'https://evil.example', '' ) );
		$this->assertSame( [], $this->sent_emails() );

		$this->assertStringContainsString( RequestNotice::PARAM . '=bad-email', GuestLinkForm::process( array_merge( $input, [ 'email' => 'nope' ] ), home_url(), '' ) );

		$url = GuestLinkForm::process( $input, '', home_url( '/board/' ) );
		$this->assertStringStartsWith( home_url( '/board/' ), $url );
		$this->assertStringContainsString( RequestNotice::PARAM . '=link-sent', $url );
		$this->assertCount( 1, $this->sent_emails() );

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
		$this->assertStringContainsString( 'Check your email. Open the link we sent you within 24 hours', $this->render_board() );

		$input['redirect_to'] = 'https://evil.example/phish';
		$this->assertStringStartsWith( home_url( '/' ), GuestLinkForm::process( $input, home_url(), '' ) );
	}

	public function test_the_email_form_is_on_boards_that_ask_for_it_and_hidden_until_needed(): void {
		$html = $this->render_board();

		$this->assertStringContainsString( 'class="mwtfrs-guest"', $html );
		$this->assertMatchesRegularExpression( '/\shidden[\s>]/', $this->email_form_tag( $html ) );
		$this->assertStringContainsString( 'name="action" value="' . GuestLinkForm::ACTION . '"', $html );
		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( '"guestVotes":true', $html );

		$this->board_rules( BoardSettings::ANYONE );
		$this->assertStringNotContainsString( 'class="mwtfrs-guest"', $this->render_board() );
		$this->board_rules( BoardSettings::LOGGED_IN );
		$this->assertStringNotContainsString( 'class="mwtfrs-guest"', $this->render_board() );
	}

	public function test_the_request_page_has_the_email_form_too(): void {
		$html = RequestView::render_details( $this->request, 'class="wp-block-mwtfrs-request mwtfrs-single"' );

		$this->assertStringContainsString( 'class="mwtfrs-guest"', $html );
		$this->assertStringContainsString( 'id="mwtfrs-request-' . $this->request . '-email"', $html );
	}

	public function test_a_confirmed_guest_gets_the_same_html_as_any_visitor_so_pages_can_be_cached(): void {
		$this->become_guest();
		$this->rest_vote( 'POST', $this->request );
		$guest = $this->render_board();
		unset( $_COOKIE[ Guests::COOKIE ] );
		$stranger = $this->render_board();

		$this->assertSame( $this->normalize( $stranger ), $this->normalize( $guest ) );
		$this->assertStringNotContainsString( 'jane@example.com', $guest );
		$this->assertStringNotContainsString( 'aria-pressed="true"', $guest );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $guest );
	}

	/**
	 * Returns the opening tag of the first form for an email address in some HTML.
	 *
	 * @param string $html HTML.
	 */
	private function email_form_tag( string $html ): string {
		$this->assertMatchesRegularExpression( '/<form\b[^>]*class="mwtfrs-guest"[^>]*>/', $html );
		preg_match( '/<form\b[^>]*class="mwtfrs-guest"[^>]*>/', $html, $tag );

		return ' ' . substr( $tag[0], 5 );
	}

	/**
	 * Renders the board block.
	 */
	private function render_board(): string {
		return render_block(
			[
				'blockName'    => Board::BLOCK,
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
	 * @param string $html Board HTML.
	 */
	private function normalize( string $html ): string {
		$html = (string) preg_replace( '/mwtfrs-board-\d+/', 'mwtfrs-board-N', $html );

		return (string) preg_replace( '/name="' . SpamGuard::FIELD_TOKEN . '" value="[^"]*"/', 'name="token" value="N"', $html );
	}
}
