<?php
/**
 * Followed requests on the profile screen tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\ProfileFollows;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Settings;

/**
 * A user sees the requests they follow on their profile, and manages them there.
 */
final class ProfileFollowsTest extends TestCase {

	/**
	 * The user whose profile is shown.
	 *
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();
		$_POST = [];

		$this->user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->user );
	}

	public function tear_down(): void {
		$_POST = [];
		parent::tear_down();
	}

	public function test_the_profile_lists_followed_requests_newest_first_with_a_box_to_stop(): void {
		$first = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		// Administrators may put markup in a title. Here it is shown as text.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$second = $this->create_request( [ 'post_title' => 'Export <b>to</b> CSV' ] );
		wp_set_current_user( $this->user );
		$pending = $this->create_request(
			[
				'post_title'  => 'Waiting for approval',
				'post_status' => 'pending',
			]
		);
		foreach ( [ $first, $second, $pending ] as $request ) {
			SubscriptionRepository::add( $request, $this->user, SubscriptionRepository::SOURCE_FOLLOW );
		}
		SubscriptionRepository::add( $this->create_request( [ 'post_title' => 'Somebody else follows this' ] ), self::factory()->user->create(), SubscriptionRepository::SOURCE_FOLLOW );

		$html = $this->section( 'show_user_profile' );

		$this->assertStringContainsString( '<h2 id="mwtfrs-following">Feature requests</h2>', $html );
		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertStringNotContainsString( 'Somebody else', $html );
		$this->assertStringContainsString(
			'<li><a href="' . esc_url( get_permalink( $first ) ) . '">Dark mode</a> &nbsp; <label><input type="checkbox" name="' . ProfileFollows::FIELD_UNFOLLOW . '[]" value="' . $first . '"> Stop following<span class="screen-reader-text"> Dark mode</span></label></li>',
			$html
		);
		$this->assertStringContainsString( '>Export &lt;b&gt;to&lt;/b&gt; CSV</a>', $html );
		$this->assertStringContainsString( '<li>Waiting for approval &nbsp; <label>', $html, 'A request that is not public has no page to link to.' );
		$this->assertLessThan( strpos( $html, 'Dark mode' ), strpos( $html, 'Waiting for approval' ), 'Newest first.' );
	}

	public function test_the_section_is_on_both_profile_screens_and_saved_with_both(): void {
		foreach ( [ 'show_user_profile', 'edit_user_profile' ] as $hook ) {
			$this->assertSame( 10, has_action( $hook, [ ProfileFollows::class, 'render' ] ), $hook );
		}
		foreach ( [ 'personal_options_update', 'edit_user_profile_update' ] as $hook ) {
			$this->assertSame( 10, has_action( $hook, [ ProfileFollows::class, 'save' ] ), $hook );
		}
	}

	public function test_the_profile_has_the_choice_about_following_by_voting(): void {
		$field = 'name="' . Subscriptions::META_AUTO_FOLLOW . '" value="1"';

		$html = $this->section( 'show_user_profile' );
		$this->assertStringContainsString( '<input type="hidden" name="' . Subscriptions::META_AUTO_FOLLOW . '" value="0"><label><input type="checkbox" ' . $field . " checked='checked'> Follow the requests I vote for or suggest</label>", $html );
		$this->assertStringContainsString( '<p>None.</p>', $html );

		Subscriptions::set_automatic( $this->user, false );
		$this->assertStringContainsString( '<input type="checkbox" ' . $field . '> Follow the requests I vote for or suggest', $this->section( 'show_user_profile' ) );
	}

	public function test_saving_the_profile_stops_following_the_ticked_requests_and_keeps_the_choice(): void {
		$stopped = $this->create_request();
		$kept    = $this->create_request();
		$other   = self::factory()->user->create();
		foreach ( [ $this->user, $other ] as $user ) {
			SubscriptionRepository::add( $stopped, $user, SubscriptionRepository::SOURCE_VOTE );
			SubscriptionRepository::add( $kept, $user, SubscriptionRepository::SOURCE_VOTE );
		}

		$this->save(
			$this->user,
			[
				ProfileFollows::FIELD_UNFOLLOW  => [ (string) $stopped ],
				Subscriptions::META_AUTO_FOLLOW => '0',
			]
		);

		$this->assertFalse( Subscriptions::is_following( $stopped, $this->user ) );
		$this->assertTrue( Subscriptions::is_following( $kept, $this->user ) );
		$this->assertTrue( Subscriptions::is_following( $stopped, $other ), 'Only the user whose profile is saved.' );
		$this->assertFalse( Subscriptions::wants_automatic( $this->user ) );
		$this->assertTrue( Subscriptions::wants_automatic( $other ) );

		$this->save( $this->user, [ Subscriptions::META_AUTO_FOLLOW => '1' ] );
		$this->assertTrue( Subscriptions::wants_automatic( $this->user ) );
		$this->assertTrue( Subscriptions::is_following( $kept, $this->user ) );
	}

	public function test_a_save_without_the_form_or_without_permission_changes_nothing(): void {
		$request = $this->create_request();
		$victim  = self::factory()->user->create();
		SubscriptionRepository::add( $request, $victim, SubscriptionRepository::SOURCE_VOTE );
		SubscriptionRepository::add( $request, $this->user, SubscriptionRepository::SOURCE_VOTE );
		$fields = [
			ProfileFollows::FIELD_UNFOLLOW  => [ (string) $request ],
			Subscriptions::META_AUTO_FOLLOW => '0',
		];

		// A subscriber may not edit another user, even with a nonce for that user's form.
		$this->save( $victim, $fields );
		// Their own profile, but not through its form.
		$this->save( $this->user, $fields, 'forged' );
		$this->save( $this->user, $fields, wp_create_nonce( 'update-user_' . $victim ) );

		foreach ( [ $victim, $this->user ] as $user ) {
			$this->assertTrue( Subscriptions::is_following( $request, $user ) );
			$this->assertTrue( Subscriptions::wants_automatic( $user ) );
		}
	}

	public function test_an_administrator_manages_the_followed_requests_of_another_user(): void {
		$request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		SubscriptionRepository::add( $request, $this->user, SubscriptionRepository::SOURCE_VOTE );
		$this->log_in_as_user_manager();

		$html = $this->section( 'edit_user_profile' );
		$this->assertStringContainsString( 'Follow the requests this user votes for or suggests', $html );
		$this->assertStringContainsString( '>Dark mode</a>', $html );

		$this->save( $this->user, [ ProfileFollows::FIELD_UNFOLLOW => [ (string) $request ] ] );
		$this->assertFalse( Subscriptions::is_following( $request, $this->user ) );
		$this->assertTrue( Subscriptions::wants_automatic( $this->user ), 'A choice that the form did not send stays as it was.' );
	}

	public function test_long_lists_are_cut_short_and_say_how_many_more_there_are(): void {
		foreach ( self::factory()->post->create_many( 102, [ 'post_type' => RequestPostType::NAME ] ) as $request ) {
			SubscriptionRepository::add( $request, $this->user, SubscriptionRepository::SOURCE_VOTE );
		}

		$html = $this->section( 'show_user_profile' );

		$this->assertSame( 100, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( '<p class="description">And 2 more.</p>', $html );
	}

	public function test_a_site_that_does_not_email_followers_shows_the_section_only_to_stop_following(): void {
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$this->assertSame( '', $this->section( 'show_user_profile' ) );

		SubscriptionRepository::add( $this->create_request( [ 'post_title' => 'Dark mode' ] ), $this->user, SubscriptionRepository::SOURCE_VOTE );
		$html = $this->section( 'show_user_profile' );

		$this->assertStringContainsString( '>Dark mode</a>', $html );
		$this->assertStringNotContainsString( Subscriptions::META_AUTO_FOLLOW, $html, 'There is nothing to follow by voting.' );
	}

	/**
	 * Returns what the plugin adds to the profile of the test's user.
	 *
	 * @param string $hook show_user_profile for one's own profile, edit_user_profile for another user's.
	 */
	private function section( string $hook ): string {
		ob_start();
		do_action( $hook, get_userdata( $this->user ) );

		return (string) ob_get_clean();
	}

	/**
	 * Saves the section of a profile form, as the profile screens do when the form is submitted.
	 *
	 * @param int                  $user_id User whose profile is saved.
	 * @param array<string, mixed> $fields  Fields the form sends.
	 * @param string|null          $nonce   Nonce the form sends. Defaults to the one of that user's form.
	 */
	private function save( int $user_id, array $fields, ?string $nonce = null ): void {
		$_POST = array_merge( $fields, [ '_wpnonce' => $nonce ?? wp_create_nonce( 'update-user_' . $user_id ) ] );

		ProfileFollows::save( $user_id );
	}
}
