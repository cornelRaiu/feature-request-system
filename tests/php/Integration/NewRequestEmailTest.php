<?php
/**
 * New request email tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\SubmissionService;
use MockPHPMailer;

/**
 * Moderators get an email about every submitted request.
 */
final class NewRequestEmailTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_the_site_admin_is_asked_to_approve_new_requests(): void {
		wp_set_current_user(
			self::factory()->user->create(
				[
					'display_name' => 'Sam Subscriber',
					'user_email'   => 'sam@example.com',
				]
			)
		);

		$request = $this->submit();
		$email   = $this->mailer()->get_sent();

		$this->assertSame( get_option( 'admin_email' ), $email->to[0][0] );
		$this->assertSame( '[Test Site] New feature request to approve: Export to CSV', $email->subject );
		$this->assertStringContainsString( 'Title: Export to CSV', $email->body );
		$this->assertStringContainsString( 'Board: Feature Requests', $email->body );
		$this->assertStringContainsString( 'Submitted by: Sam Subscriber <sam@example.com>', $email->body );
		$this->assertStringContainsString( 'For our accountant.', $email->body );
		$this->assertStringContainsString( admin_url( 'post.php?post=' . $request . '&action=edit' ), $email->body );
	}

	public function test_requests_published_straight_away_are_announced_as_such(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::MODERATION => false ] );
		wp_set_current_user( self::factory()->user->create() );

		$this->submit();

		$this->assertSame( '[Test Site] New feature request: Export to CSV', $this->mailer()->get_sent()->subject );
		$this->assertStringContainsString( 'It is already on the board.', $this->mailer()->get_sent()->body );
	}

	public function test_guests_are_described_by_the_name_and_email_they_gave(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );

		$this->submit(
			[
				'name'  => 'Ana',
				'email' => 'ana@example.com',
			]
		);

		$this->assertStringContainsString( 'Submitted by: Ana <ana@example.com>', $this->mailer()->get_sent()->body );
	}

	public function test_special_characters_arrive_as_typed_in_the_plain_text_email(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->submit(
			[
				'title'       => 'Q&A for "pro" users',
				'description' => 'Use [brackets] & <angles>',
			]
		);
		$email = $this->mailer()->get_sent();

		$this->assertStringContainsString( 'Q&A for "pro" users', $email->subject );
		$this->assertStringContainsString( 'Use [brackets] &', $email->body );
	}

	public function test_requests_flagged_as_spam_say_so(): void {
		add_filter( SpamGuard::FILTER_SPAM, '__return_true' );
		wp_set_current_user( self::factory()->user->create() );

		$this->submit();

		$this->assertStringContainsString( 'A spam check flagged this request', $this->mailer()->get_sent()->body );
	}

	public function test_emails_go_to_the_configured_addresses_instead(): void {
		Settings::update( Settings::NOTIFY_EMAILS, 'team@example.com, not an email, lead@example.com' );
		wp_set_current_user( self::factory()->user->create() );

		$this->submit();

		$this->assertSame( [ 'team@example.com', 'lead@example.com' ], array_column( $this->mailer()->get_sent()->to, 0 ) );
	}

	public function test_sites_can_turn_the_emails_off(): void {
		Settings::update( Settings::NOTIFY_NEW_REQUESTS, false );
		wp_set_current_user( self::factory()->user->create() );

		$this->submit();

		$this->assertFalse( $this->mailer()->get_sent() );
	}

	/**
	 * Submits a request to the default board.
	 *
	 * @param array<string, mixed> $fields Fields to override in a valid submission.
	 * @return int Request ID.
	 */
	private function submit( array $fields = [] ): int {
		$result = SubmissionService::submit( $this->default_board(), $this->submission( $fields ) );

		$this->assertIsArray( $result );

		return $result['id'];
	}

	/**
	 * The test mailer, which keeps sent emails.
	 */
	private function mailer(): MockPHPMailer {
		return tests_retrieve_phpmailer_instance();
	}
}
