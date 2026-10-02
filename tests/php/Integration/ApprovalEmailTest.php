<?php
/**
 * Approval email tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Notifications\ApprovalEmail;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Notifications\Unsubscribe;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SubmissionService;

/**
 * A logged-in user hears when the request they suggested is approved.
 */
final class ApprovalEmailTest extends TestCase {

	/**
	 * The user who suggests the requests.
	 *
	 * @var int
	 */
	private $submitter = 0;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$this->submitter = self::factory()->user->create(
			[
				'display_name' => 'Sam Subscriber',
				'user_email'   => 'sam@example.com',
			]
		);
		wp_set_current_user( $this->submitter );
		// The email to moderators about a new request is another class's business.
		Settings::update( Settings::NOTIFY_NEW_REQUESTS, false );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_the_submitter_is_told_in_the_background_when_a_moderator_approves_the_request(): void {
		$request  = $this->submit();
		$stop_url = Unsubscribe::url( SubscriptionRepository::find( $request, $this->submitter )['token'] );

		$this->approve( $request );

		$this->assertSame( [], $this->emailed(), 'Approving sends nothing itself.' );
		$this->assertSame( [ [ $request ] ], $this->waiting_args() );
		$this->assertSame( 1, $this->run_waiting_actions( ApprovalEmail::HOOK ) );

		$email = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'sam@example.com', $email->to[0][0] );
		$this->assertSame( '[Test Site] Your feature request was approved: Export to CSV', $email->subject );
		$this->assertSame(
			implode(
				"\n",
				[
					'Hi Sam Subscriber,',
					'',
					'Your feature request on Test Site was approved. Everyone can now see it and vote for it:',
					'',
					'Export to CSV',
					get_permalink( $request ),
					'',
					'You follow this request, so you will get an email when its status changes.',
					'',
					'--',
					'You get this email because you suggested this request.',
					'Stop following it: ' . $stop_url,
				]
			),
			trim( str_replace( "\r\n", "\n", $email->body ) )
		);
		$this->assertSame( '<' . $stop_url . '>', iconv_mime_decode_headers( $email->header, 0, 'UTF-8' )['List-Unsubscribe'] );
	}

	public function test_names_and_titles_arrive_as_typed_in_the_plain_text_email(): void {
		// WordPress stores both with the ampersand as an entity.
		update_option( 'blogname', 'R&D Team' );
		$request = $this->submit( [ 'title' => 'Tom & Jerry\'s "night" mode' ] );

		$this->approve( $request );
		$this->run_waiting_actions( ApprovalEmail::HOOK );

		$email = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( '[R&D Team] Your feature request was approved: Tom & Jerry\'s "night" mode', $email->subject );
		$this->assertStringContainsString( "Your feature request on R&D Team was approved.", $email->body );
		$this->assertStringContainsString( "Tom & Jerry's \"night\" mode\n", str_replace( "\r\n", "\n", $email->body ) );
	}

	public function test_only_the_approval_itself_sends_the_email_not_later_saves_or_republishing(): void {
		$request = $this->submit();
		$this->approve( $request );
		$this->run_waiting_actions( ApprovalEmail::HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		wp_update_post(
			[
				'ID'         => $request,
				'post_title' => 'Export to CSV and Excel',
			]
		);
		wp_trash_post( $request );
		wp_untrash_post( $request );
		wp_publish_post( $request );

		$this->assertSame( 'publish', get_post_status( $request ) );
		$this->assertSame( [], $this->waiting_args() );
	}

	public function test_a_request_that_is_published_straight_away_needs_no_approval_email(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::MODERATION => false ] );

		$request = $this->submit();

		$this->assertSame( 'publish', get_post_status( $request ) );
		$this->assertSame( [], $this->waiting_args() );
	}

	public function test_a_visitor_without_an_account_is_not_emailed(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
		wp_set_current_user( 0 );
		$request = $this->submit( [ 'email' => 'ana@example.com' ] );

		$this->approve( $request );

		$this->assertSame( [], $this->waiting_args() );
	}

	public function test_a_submitter_who_does_not_follow_the_request_is_not_emailed(): void {
		$unfollowed = $this->submit();
		Subscriptions::unfollow( $unfollowed );
		Subscriptions::set_automatic( $this->submitter, false );
		$never = $this->submit( [ 'title' => 'Import from CSV' ] );

		$this->approve( $unfollowed );
		$this->approve( $never );

		$this->assertSame( [], $this->waiting_args() );
	}

	public function test_a_submitter_who_stops_following_before_the_email_goes_out_is_not_emailed(): void {
		$request = $this->submit();
		$this->approve( $request );

		Unsubscribe::process( SubscriptionRepository::find( $request, $this->submitter )['token'], true, false );
		$this->run_waiting_actions( ApprovalEmail::HOOK );

		$this->assertSame( [], $this->emailed() );
	}

	public function test_no_email_goes_out_when_the_request_was_unpublished_again_or_emails_were_turned_off(): void {
		$unpublished = $this->submit();
		$emails_off  = $this->submit( [ 'title' => 'Import from CSV' ] );

		$this->approve( $unpublished );
		wp_trash_post( $unpublished );
		$this->assertSame( 1, $this->run_waiting_actions( ApprovalEmail::HOOK ) );
		$this->assertSame( [], $this->emailed(), 'The request is no longer public.' );

		$this->approve( $emails_off );
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$this->assertSame( 1, $this->run_waiting_actions( ApprovalEmail::HOOK ) );
		$this->assertSame( [], $this->emailed(), 'The site turned the emails off.' );
	}

	public function test_approving_while_the_site_does_not_email_followers_schedules_nothing(): void {
		$request = $this->submit();
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );

		$this->approve( $request );

		$this->assertTrue( Subscriptions::is_following( $request, $this->submitter ) );
		$this->assertSame( [], $this->waiting_args() );
	}

	public function test_a_moderator_who_publishes_their_own_pending_request_is_not_emailed(): void {
		$request = $this->submit();
		// A request of a moderator waits for approval when a spam check held it. Make this one theirs.
		$moderator = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_post_meta( $request, RequestPostType::META_SUBMITTER_ID, $moderator );
		SubscriptionRepository::add( $request, $moderator, SubscriptionRepository::SOURCE_AUTHOR );

		wp_set_current_user( $moderator );
		wp_publish_post( $request );

		$this->assertSame( [], $this->waiting_args() );
	}

	public function test_publishing_other_content_schedules_nothing(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'pending' ] );

		wp_publish_post( $post );

		$this->assertSame( [], $this->waiting_args() );
	}

	/**
	 * Submits a request as the current visitor and returns its ID. It waits for approval
	 * unless the board says otherwise.
	 *
	 * @param array<string, mixed> $fields Fields to override in a valid submission.
	 */
	private function submit( array $fields = [] ): int {
		return SubmissionService::submit( $this->default_board(), $this->submission( $fields ) )['id'];
	}

	/**
	 * Publishes a pending request as a moderator, then switches back to the submitter.
	 *
	 * @param int $request_id Request ID.
	 */
	private function approve( int $request_id ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		wp_publish_post( $request_id );
		wp_set_current_user( $this->submitter );
	}

	/**
	 * Returns the arguments of the approval emails that wait to be sent.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function waiting_args(): array {
		return array_values(
			array_map(
				static function ( $action ): array {
					return $action->get_args();
				},
				$this->waiting_actions( ApprovalEmail::HOOK )
			)
		);
	}
}
