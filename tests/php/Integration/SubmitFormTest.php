<?php
/**
 * Submitting without JavaScript tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Submission\SpamGuard;

/**
 * The form's plain post submits the request and sends the visitor back with a notice.
 */
final class SubmitFormTest extends TestCase {

	private const PAGE_URL = 'http://example.org/roadmap/';

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
	}

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	public function test_logged_in_user_submits_and_returns_to_the_form(): void {
		wp_set_current_user( self::factory()->user->create() );

		$url = SubmitForm::process( $this->input( wp_create_nonce( SubmitForm::ACTION ) ), '', '' );

		$this->assertSame( self::PAGE_URL . '?fr-form=pending#' . SubmitForm::anchor( $this->default_board() ), $url );
		$this->assertSame( [ 'Export to CSV' ], $this->titles() );
	}

	public function test_published_requests_get_their_own_notice(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertStringContainsString( 'fr-form=published', SubmitForm::process( $this->input( wp_create_nonce( SubmitForm::ACTION ) ), '', '' ) );
	}

	public function test_logged_in_post_with_a_bad_nonce_is_refused(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertStringContainsString( 'fr-form=expired', SubmitForm::process( $this->input( 'forged' ), '', '' ) );
		$this->assertSame( [], $this->titles() );
	}

	public function test_guest_posts_must_come_from_this_site(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );

		$this->assertStringContainsString( 'fr-form=origin', SubmitForm::process( $this->input(), 'https://evil.example', '' ) );
		$this->assertSame( [], $this->titles() );

		$this->assertStringContainsString( 'fr-form=pending', SubmitForm::process( $this->input(), '', 'http://example.org/roadmap/' ) );
		$this->assertSame( [ 'Export to CSV' ], $this->titles() );
	}

	public function test_refusals_become_notices(): void {
		$this->assertStringContainsString( 'fr-form=login', SubmitForm::process( $this->input(), 'http://example.org', '' ), 'Guests on a board for logged-in users.' );

		wp_set_current_user( self::factory()->user->create() );
		$nonce = wp_create_nonce( SubmitForm::ACTION );

		$this->assertStringContainsString( 'fr-form=invalid-title', SubmitForm::process( $this->input( $nonce, [ 'title' => '' ] ), '', '' ) );
		$this->assertStringContainsString( 'fr-form=rejected', SubmitForm::process( $this->input( $nonce, [ SpamGuard::FIELD_TRAP => 'filled' ] ), '', '' ) );
		$this->assertSame( [], $this->titles() );
	}

	public function test_redirects_never_leave_the_site(): void {
		wp_set_current_user( self::factory()->user->create() );

		$url = SubmitForm::process( $this->input( wp_create_nonce( SubmitForm::ACTION ), [ 'redirect_to' => 'https://evil.example/phish' ] ), '', '' );

		$this->assertStringStartsWith( home_url( '/' ), $url );
	}

	public function test_notice_is_read_from_the_url_only_when_known(): void {
		$_GET[ SubmitForm::PARAM_NOTICE ] = 'login';
		$this->assertSame(
			[
				'message' => 'Log in to suggest a feature.',
				'error'   => true,
				'login'   => true,
			],
			SubmitForm::notice_from_request()
		);

		$_GET[ SubmitForm::PARAM_NOTICE ] = 'published';
		$this->assertFalse( SubmitForm::notice_from_request()['error'] );

		foreach ( [ 'made-up', 'invalid-made-up' ] as $code ) {
			$_GET[ SubmitForm::PARAM_NOTICE ] = $code;
			$this->assertNull( SubmitForm::notice_from_request(), $code );
		}
	}

	/**
	 * Builds the fields the form posts.
	 *
	 * @param string               $nonce  Nonce, which the form only includes for logged-in users.
	 * @param array<string, mixed> $fields Fields to override.
	 * @return array<string, mixed>
	 */
	private function input( string $nonce = '', array $fields = [] ): array {
		return array_merge(
			$this->submission(),
			array_filter(
				[
					'action'      => SubmitForm::ACTION,
					'board'       => (string) $this->default_board(),
					'redirect_to' => self::PAGE_URL,
					'_wpnonce'    => $nonce,
				]
			),
			$fields
		);
	}

	/**
	 * Titles of the requests saved so far, in any status.
	 *
	 * @return string[]
	 */
	private function titles(): array {
		return wp_list_pluck(
			get_posts(
				[
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'any',
					'numberposts' => -1,
				]
			),
			'post_title'
		);
	}
}
