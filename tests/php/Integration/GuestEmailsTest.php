<?php
/**
 * Tests of the emails to followers who are guests with a confirmed email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Guests\GuestRepository;
use FeatureRequestSystem\Guests\Guests;
use FeatureRequestSystem\Notifications\ApprovalEmail;
use FeatureRequestSystem\Notifications\Mailer;
use FeatureRequestSystem\Notifications\StatusNotifier;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Notifications\Unsubscribe;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Settings;
use WP_REST_Request;

/**
 * A guest who confirmed their email follows the requests they vote for or suggest, gets
 * the same emails as a user, and stops them with the same link.
 */
final class GuestEmailsTest extends TestCase {

	use GuestHelpers;

	/**
	 * A request on the default board.
	 *
	 * @var int
	 */
	private $request = 0;

	/**
	 * The guest who follows it.
	 *
	 * @var int
	 */
	private $guest = 0;

	public function set_up(): void {
		parent::set_up();
		$this->forget_visitor();
		$this->board_rules( BoardSettings::VERIFIED, BoardSettings::VERIFIED );
		Settings::update( Settings::NOTIFY_NEW_REQUESTS, false );
		add_filter( StatusNotifier::FILTER_DELAY, '__return_zero' );

		$this->request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->guest   = $this->become_guest( 'jane@example.com', 'Jane' );
		$this->rest_vote( 'POST', $this->request );
		unset( $_COOKIE[ Guests::COOKIE ] );
	}

	public function tear_down(): void {
		$this->forget_visitor();
		parent::tear_down();
	}

	public function test_a_status_change_emails_the_guest_with_the_link_that_stops_it(): void {
		$stop = Unsubscribe::url( SubscriptionRepository::find_guest( $this->request, $this->guest )['token'] );

		$this->change_status( 'planned' );

		$this->assertSame( [ 'jane@example.com' ], $this->emailed() );
		$email = $this->sent_emails()[0];
		$this->assertStringContainsString( 'Status changed to Planned: Dark mode', $email['subject'] );
		$this->assertStringContainsString( 'Hi Jane,', $email['body'] );
		$this->assertStringContainsString( 'You get this email because you voted for this request.', $email['body'] );
		$this->assertStringContainsString( 'Stop following it: ' . $stop, $email['body'] );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $email['header'] );
	}

	public function test_a_change_made_by_code_without_a_user_still_reaches_the_guest(): void {
		wp_set_current_user( 0 );

		$this->change_status( 'planned' );

		$this->assertSame( [ 'jane@example.com' ], $this->emailed() );
	}

	public function test_a_guest_without_a_name_is_greeted_by_their_address(): void {
		$guest = $this->become_guest( 'nameless@example.com', '' );
		$this->rest_vote( 'POST', $this->request );

		$this->change_status( 'planned' );

		$emails = $this->sent_emails();
		$this->assertCount( 2, $emails );
		$this->assertSame( 'nameless@example.com', $emails[1]['to'][0][0] );
		$this->assertStringContainsString( 'Hi nameless@example.com,', $emails[1]['body'] );
		$this->assertNotNull( SubscriptionRepository::find_guest( $this->request, $guest ) );
	}

	public function test_code_learns_which_subscription_an_email_is_for(): void {
		$seen = [];
		add_filter(
			Mailer::FILTER,
			static function ( array $email, string $template, $request, $user, array $subscription ) use ( &$seen ): array {
				$seen[] = [ $user, $subscription['guest_id'] ];
				return $email;
			},
			10,
			5
		);

		$this->change_status( 'planned' );

		$this->assertSame( [ [ null, $this->guest ] ], $seen );
	}

	public function test_the_link_in_the_email_stops_the_guests_emails_about_that_request(): void {
		$other = $this->create_request();
		SubscriptionRepository::add_guest( $other, $this->guest, SubscriptionRepository::SOURCE_FOLLOW );
		$token = SubscriptionRepository::find_guest( $this->request, $this->guest )['token'];

		$asked = Unsubscribe::process( $token, false, false );
		$this->assertStringContainsString( 'You follow 2 requests on this site.', $asked['html'] );
		$this->assertStringNotContainsString( 'profile', $asked['html'], 'A guest has no profile to change it in.' );

		$done = Unsubscribe::process( $token, true, false );
		$this->assertStringContainsString( '<h1>You no longer follow this request</h1>', $done['html'] );
		$this->assertNull( SubscriptionRepository::find_guest( $this->request, $this->guest ) );
		$this->assertNotNull( SubscriptionRepository::find_guest( $other, $this->guest ) );

		$this->change_status( 'planned' );
		$this->assertSame( [], $this->emailed() );
	}

	public function test_a_guest_can_stop_every_email_and_then_votes_without_following(): void {
		$other = $this->create_request();
		SubscriptionRepository::add_guest( $other, $this->guest, SubscriptionRepository::SOURCE_FOLLOW );
		$user = $this->create_followers( $this->request, 1 )[0];
		$token = SubscriptionRepository::find_guest( $this->request, $this->guest )['token'];

		$page = Unsubscribe::process( $token, true, true );

		$this->assertStringContainsString( '<h1>You no longer follow any request</h1>', $page['html'] );
		$this->assertStringNotContainsString( 'profile.php', $page['html'] );
		$this->assertSame( 0, SubscriptionRepository::count_of_guest( $this->guest ) );
		$this->assertFalse( GuestRepository::find( $this->guest )['auto_follow'] );
		$this->assertTrue( Subscriptions::is_following( $this->request, $user ), 'Users who follow the same request are left alone.' );

		$this->become_guest( 'jane@example.com' );
		$third = $this->create_request();
		$this->rest_vote( 'POST', $third );
		$this->assertCount( 1, $this->vote_rows_of( $third ) );
		$this->assertNull( SubscriptionRepository::find_guest( $third, $this->guest ) );
	}

	public function test_a_follower_whose_guest_was_erased_is_skipped_and_forgotten(): void {
		GuestRepository::delete( $this->guest );

		$this->change_status( 'planned' );

		$this->assertSame( [], $this->emailed() );
		$this->assertSame( 0, SubscriptionRepository::count( $this->request ) );
	}

	public function test_the_guest_who_suggested_a_request_is_told_when_it_is_approved(): void {
		$this->become_guest( 'jane@example.com' );
		$request = $this->rest_submit( 'Calendar view' );
		$this->assertSame( 'pending', get_post_status( $request ) );
		$stop = Unsubscribe::url( SubscriptionRepository::find_guest( $request, $this->guest )['token'] );
		unset( $_COOKIE[ Guests::COOKIE ] );
		reset_phpmailer_instance();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		wp_publish_post( $request );
		$this->assertSame( 1, $this->run_waiting_actions( ApprovalEmail::HOOK ) );

		$this->assertSame( [ 'jane@example.com' ], $this->emailed() );
		$email = $this->sent_emails()[0];
		$this->assertStringContainsString( 'Your feature request was approved: Calendar view', $email['subject'] );
		$this->assertStringContainsString( 'Stop following it: ' . $stop, $email['body'] );
	}

	public function test_a_visitor_who_never_confirmed_an_address_is_not_told_of_an_approval(): void {
		$this->board_rules( BoardSettings::VERIFIED, BoardSettings::ANYONE );
		$request = $this->rest_submit( 'Calendar view', 'someone@example.com' );
		reset_phpmailer_instance();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		wp_publish_post( $request );

		$this->assertSame( [], $this->waiting_actions( ApprovalEmail::HOOK ) );
		$this->assertSame( '', get_post_meta( $request, RequestPostType::META_SUBMITTER_GUEST, true ) );
	}

	/**
	 * Changes the request's status and sends the emails that are due.
	 *
	 * @param string $slug Slug of the new status.
	 */
	private function change_status( string $slug ): void {
		wp_set_object_terms( $this->request, $this->status( $slug ), StatusTaxonomy::NAME );
		$this->run_waiting_actions( StatusNotifier::HOOK );
	}

	/**
	 * Suggests a feature through the REST API as the current visitor, and returns its ID.
	 *
	 * @param string $title Title.
	 * @param string $email Email address given.
	 */
	private function rest_submit( string $title, string $email = 'jane@example.com' ): int {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/boards/' . $this->default_board() . '/requests' );
		$request->set_header( 'Origin', home_url() );
		$request->set_body_params(
			$this->submission(
				[
					'title' => $title,
					'name'  => 'Jane',
					'email' => $email,
				]
			)
		);

		return (int) rest_do_request( $request )->get_data()['id'];
	}
}
