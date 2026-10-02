<?php
/**
 * Following without JavaScript tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Frontend\FollowForm;
use FeatureRequestSystem\Frontend\RequestItem;
use FeatureRequestSystem\Frontend\RequestNotice;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Settings;

/**
 * The Follow button's plain form post follows or stops, and sends the user back with a notice.
 */
final class FollowFormTest extends TestCase {

	private const PAGE_URL = 'http://example.org/feature-requests/dark-mode/';

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
	}

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	public function test_the_form_follows_then_stops_and_returns_to_the_request(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$request = $this->create_request();

		$first = FollowForm::process( $this->input( $request, wp_create_nonce( FollowForm::ACTION ) ) );
		$this->assertSame( self::PAGE_URL . '?fr-notice=following&fr-request=' . $request . '#' . RequestItem::dom_id( $request ), $first );
		$this->assertTrue( Subscriptions::is_following( $request, $user ) );

		$second = FollowForm::process( $this->input( $request, wp_create_nonce( FollowForm::ACTION ) ) );
		$this->assertStringContainsString( 'fr-notice=unfollowed', $second );
		$this->assertFalse( Subscriptions::is_following( $request, $user ) );
	}

	public function test_the_form_is_answered_for_users_and_for_those_whose_login_ended(): void {
		foreach ( [ 'admin_post_', 'admin_post_nopriv_' ] as $hook ) {
			$this->assertSame( 10, has_action( $hook . FollowForm::ACTION, [ FollowForm::class, 'handle' ] ), $hook );
		}
	}

	public function test_a_post_with_a_bad_nonce_or_without_a_login_changes_nothing(): void {
		$user    = self::factory()->user->create();
		$request = $this->create_request();
		$nonce   = wp_create_nonce( FollowForm::ACTION );

		$this->assertStringContainsString( 'fr-notice=follow-login', FollowForm::process( $this->input( $request, $nonce ) ) );

		wp_set_current_user( $user );
		$this->assertStringContainsString( 'fr-notice=expired', FollowForm::process( $this->input( $request, 'forged' ) ) );
		$this->assertStringContainsString( 'fr-notice=expired', FollowForm::process( $this->input( $request, wp_create_nonce( 'another-action' ) ) ) );
		$this->assertFalse( Subscriptions::is_following( $request, $user ) );
	}

	public function test_a_refusal_becomes_a_notice(): void {
		wp_set_current_user( self::factory()->user->create() );
		$pending = $this->create_request( [ 'post_status' => 'pending' ] );
		$nonce   = wp_create_nonce( FollowForm::ACTION );

		$this->assertStringContainsString( 'fr-notice=follow-error', FollowForm::process( $this->input( $pending, $nonce ) ) );

		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$this->assertStringContainsString( 'fr-notice=follow-error', FollowForm::process( $this->input( $this->create_request(), $nonce ) ) );
	}

	public function test_redirects_never_leave_the_site(): void {
		wp_set_current_user( self::factory()->user->create() );
		$input                = $this->input( $this->create_request(), wp_create_nonce( FollowForm::ACTION ) );
		$input['redirect_to'] = 'https://evil.example/phish';

		$this->assertStringStartsWith( home_url( '/' ), FollowForm::process( $input ) );
	}

	public function test_the_notices_about_following_have_their_messages(): void {
		$messages = [
			'following'    => [ 'You now follow this request.', false ],
			'unfollowed'   => [ 'You no longer follow this request.', false ],
			'follow-login' => [ 'Log in to follow this request.', true ],
			'follow-error' => [ 'That could not be saved. Try again.', false ],
		];

		foreach ( $messages as $code => list( $message, $login ) ) {
			$_GET = [
				RequestNotice::PARAM         => $code,
				RequestNotice::PARAM_REQUEST => '12',
			];

			$this->assertSame(
				[
					'request' => 12,
					'message' => $message,
					'login'   => $login,
				],
				RequestNotice::from_request(),
				$code
			);
		}
	}

	/**
	 * Builds the fields the follow form posts.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $nonce      Nonce.
	 * @return array<string, string>
	 */
	private function input( int $request_id, string $nonce ): array {
		return [
			'request_id'  => (string) $request_id,
			'redirect_to' => self::PAGE_URL,
			'_wpnonce'    => $nonce,
		];
	}
}
