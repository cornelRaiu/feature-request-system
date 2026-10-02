<?php
/**
 * Unsubscribe link tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Notifications\Unsubscribe;
use FeatureRequestSystem\Voting\VoteService;
use WPDieException;

/**
 * The link at the end of every email stops the emails, without logging in.
 */
final class UnsubscribeTest extends TestCase {

	/**
	 * A request with one follower.
	 *
	 * @var int
	 */
	private $request = 0;

	/**
	 * The follower.
	 *
	 * @var int
	 */
	private $user = 0;

	/**
	 * The token of the follower's link.
	 *
	 * @var string
	 */
	private $token = '';

	public function set_up(): void {
		parent::set_up();
		$_GET                   = [];
		$_POST                  = [];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$this->request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->user    = $this->create_followers( $this->request, 1 )[0];
		$this->token   = SubscriptionRepository::find( $this->request, $this->user )['token'];
	}

	public function tear_down(): void {
		$_GET                      = [];
		$_POST                     = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	public function test_the_link_is_on_the_home_page_and_carries_the_token(): void {
		$this->assertSame( home_url( '/' ) . '?' . Unsubscribe::PARAM . '=' . $this->token, Unsubscribe::url( $this->token ) );
	}

	public function test_opening_the_link_only_asks_so_a_program_that_scans_links_unsubscribes_nobody(): void {
		$page = Unsubscribe::process( $this->token, false, false );

		$this->assertTrue( Subscriptions::is_following( $this->request, $this->user ) );
		$this->assertSame( 200, $page['status'] );
		$this->assertSame( 'Stop following this request?', $page['title'] );
		$this->assertStringContainsString( '<p>You will get no more emails about &quot;Dark mode&quot;.</p>', $page['html'] );
		$this->assertStringContainsString( '<form method="post" action="' . esc_url( Unsubscribe::url( $this->token ) ) . '"><p><button type="submit" class="button button-large">Stop following</button>', $page['html'] );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $this->request ) ) . '">Keep following and go to the request</a>', $page['html'] );
	}

	public function test_posting_to_the_link_stops_the_emails_about_that_request_only(): void {
		$other = $this->create_request();
		SubscriptionRepository::add( $other, $this->user, SubscriptionRepository::SOURCE_VOTE );
		$bystander = $this->create_followers( $this->request, 1 )[0];

		$page = Unsubscribe::process( $this->token, true, false );

		$this->assertFalse( Subscriptions::is_following( $this->request, $this->user ) );
		$this->assertTrue( Subscriptions::is_following( $other, $this->user ) );
		$this->assertTrue( Subscriptions::is_following( $this->request, $bystander ) );
		$this->assertTrue( Subscriptions::wants_automatic( $this->user ) );
		$this->assertSame( 200, $page['status'] );
		$this->assertSame( 'You no longer follow this request', $page['title'] );
		$this->assertStringContainsString( 'We will not email you about &quot;Dark mode&quot; again.', $page['html'] );
		$this->assertStringContainsString( 'You still follow 1 other request on this site.', $page['html'] );
	}

	public function test_a_link_works_once_and_a_wrong_token_never(): void {
		Unsubscribe::process( $this->token, true, false );
		$bystander = $this->create_followers( $this->request, 1 )[0];
		$valid     = SubscriptionRepository::find( $this->request, $bystander )['token'];

		$wrong = [
			'used'           => $this->token,
			'made up'        => str_repeat( 'a', 32 ),
			'empty'          => '',
			'too long'       => $valid . 'a',
			// The database compares text without regard to case, the link must not.
			'different case' => strtolower( $valid ) === $valid ? strtoupper( $valid ) : strtolower( $valid ),
			'wildcards'      => str_repeat( '%', 32 ),
		];

		foreach ( $wrong as $case => $token ) {
			$page = Unsubscribe::process( $token, true, true );

			$this->assertSame( 404, $page['status'], $case );
			$this->assertSame( 'This link no longer works', $page['title'], $case );
			$this->assertStringContainsString( '<a href="' . esc_url( home_url( '/' ) ) . '">Go to Test Site</a>', $page['html'], $case );
		}
		$this->assertTrue( Subscriptions::is_following( $this->request, $bystander ) );
		$this->assertTrue( Subscriptions::wants_automatic( $bystander ) );
	}

	public function test_the_user_can_stop_every_email_at_once_and_then_votes_without_following(): void {
		$others = [ $this->create_request(), $this->create_request() ];
		foreach ( $others as $other ) {
			SubscriptionRepository::add( $other, $this->user, SubscriptionRepository::SOURCE_VOTE );
		}
		$bystander = $this->create_followers( $this->request, 1 )[0];

		$asked = Unsubscribe::process( $this->token, false, false );
		$this->assertStringContainsString( 'You follow 3 requests on this site. You can also stop all of these emails. Voting for a request will then no longer make you follow it.', $asked['html'] );
		$this->assertStringContainsString( '<input type="hidden" name="all" value="1"><p><button type="submit" class="button button-large">Stop all emails</button>', $asked['html'] );

		$page = Unsubscribe::process( $this->token, true, true );

		$this->assertSame( 0, SubscriptionRepository::count_of_user( $this->user ) );
		$this->assertTrue( Subscriptions::is_following( $this->request, $bystander ) );
		$this->assertSame( 'You no longer follow any request', $page['title'] );
		$this->assertStringContainsString( '<a href="' . esc_url( admin_url( 'profile.php' ) ) . '">your profile</a>', $page['html'] );

		wp_set_current_user( $this->user );
		VoteService::cast( $others[0] );
		$this->assertFalse( Subscriptions::is_following( $others[0], $this->user ) );
		$this->assertSame( [ 'following' => true ], Subscriptions::follow( $others[0] ), 'Choosing to follow still works.' );
	}

	public function test_after_stopping_one_the_page_offers_to_stop_the_rest_with_a_link_that_still_works(): void {
		$other = $this->create_request();
		SubscriptionRepository::add( $other, $this->user, SubscriptionRepository::SOURCE_VOTE );
		$remaining = SubscriptionRepository::find( $other, $this->user )['token'];

		$page = Unsubscribe::process( $this->token, true, false );

		$this->assertStringContainsString( '<form method="post" action="' . esc_url( Unsubscribe::url( $remaining ) ) . '"><input type="hidden" name="all" value="1">', $page['html'] );
		$this->assertStringNotContainsString( $this->token, $page['html'] );

		Unsubscribe::process( $remaining, true, true );
		$this->assertSame( 0, SubscriptionRepository::count_of_user( $this->user ) );
	}

	public function test_nothing_more_is_offered_to_a_user_who_follows_nothing_else(): void {
		$page = Unsubscribe::process( $this->token, true, false );

		$this->assertStringNotContainsString( '<form', $page['html'] );
		$this->assertStringContainsString( 'Go to the request', $page['html'] );
	}

	public function test_a_request_that_is_not_public_is_not_named_or_linked(): void {
		wp_update_post(
			[
				'ID'          => $this->request,
				'post_status' => 'pending',
			]
		);

		$asked = Unsubscribe::process( $this->token, false, false );
		$done  = Unsubscribe::process( $this->token, true, false );

		foreach ( [ $asked, $done ] as $page ) {
			$this->assertStringNotContainsString( 'Dark mode', $page['html'] );
			$this->assertStringNotContainsString( 'go to the request', strtolower( $page['html'] ) );
		}
		$this->assertStringContainsString( 'You will get no more emails about this request.', $asked['html'] );
		$this->assertStringContainsString( 'We will not email you about this request again.', $done['html'] );
		$this->assertFalse( Subscriptions::is_following( $this->request, $this->user ), 'The link still works.' );
	}

	public function test_a_title_cannot_add_markup_to_the_page(): void {
		wp_update_post(
			[
				'ID'         => $this->request,
				'post_title' => 'Dark &lt;script&gt;alert(1)&lt;/script&gt; mode',
			]
		);

		$page = Unsubscribe::process( $this->token, false, false );

		$this->assertStringNotContainsString( '<script', $page['html'] );
		$this->assertStringContainsString( 'Dark &lt;script&gt;alert(1)&lt;/script&gt; mode', $page['html'] );
	}

	public function test_a_visit_to_the_link_ends_with_the_page_and_other_visits_are_left_alone(): void {
		Unsubscribe::handle();
		$this->assertTrue( Subscriptions::is_following( $this->request, $this->user ), 'A visit without the parameter is none of its business.' );

		$_GET[ Unsubscribe::PARAM ] = $this->token;
		$asked                      = $this->die_message();
		$this->assertStringContainsString( '<h1>Stop following this request?</h1>', $asked );
		$this->assertTrue( Subscriptions::is_following( $this->request, $this->user ) );

		// What a mail program sends for its own unsubscribe button: a post without cookies.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [ 'List-Unsubscribe' => 'One-Click' ];
		$done                      = $this->die_message();
		$this->assertStringContainsString( '<h1>You no longer follow this request</h1>', $done );
		$this->assertFalse( Subscriptions::is_following( $this->request, $this->user ) );

		$_GET[ Unsubscribe::PARAM ] = [ 'not', 'a', 'token' ];
		$this->assertStringContainsString( '<h1>This link no longer works</h1>', $this->die_message() );
	}

	/**
	 * Lets the link answer the current visit and returns the page it ends with.
	 */
	private function die_message(): string {
		try {
			Unsubscribe::handle();
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'The visit did not end with a page.' );
	}
}
