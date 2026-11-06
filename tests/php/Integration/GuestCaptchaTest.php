<?php
/**
 * Tests of the Turnstile check on the email form next to a vote button.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\GuestLinkForm;
use FeatureRequestSystem\Frontend\RequestNotice;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\Turnstile;

/**
 * While the site has Turnstile on, a visitor who asks for a link to confirm their email
 * passes the check too. The widget waits in the form until it opens, and Cloudflare's
 * script loads only on pages that have such a form.
 */
final class GuestCaptchaTest extends TestCase {

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
		Settings::update( Settings::TURNSTILE_SITE_KEY, '1x00000000000000000000AA' );
		Settings::update( Settings::TURNSTILE_SECRET_KEY, '1x0000000000000000000000000000000AA' );
		wp_dequeue_script( Turnstile::SCRIPT );
		wp_deregister_script( Turnstile::SCRIPT );
		// Cloudflare is never asked: every answer it would give is a refusal here.
		add_filter(
			'pre_http_request',
			static function () {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => '{"success":false}',
				];
			}
		);
	}

	public function tear_down(): void {
		$this->forget_visitor();
		wp_dequeue_script( Turnstile::SCRIPT );
		wp_deregister_script( Turnstile::SCRIPT );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_the_email_form_has_a_place_for_the_widget_and_the_page_loads_cloudflares_script(): void {
		$html = $this->render_board();

		$this->assertStringContainsString( 'class="mwtfrs-guest__captcha" data-sitekey="1x00000000000000000000AA" data-response-field-name="' . Turnstile::FIELD . '"', $html );
		$this->assertStringNotContainsString( 'class="cf-turnstile"', $html, 'Cloudflare draws nothing by itself: the script draws the widget when the form opens.' );
		$this->assertTrue( wp_script_is( Turnstile::SCRIPT, 'enqueued' ) );
	}

	public function test_boards_without_the_email_form_load_nothing_from_cloudflare(): void {
		$this->board_rules( BoardSettings::ANYONE );
		$html = $this->render_board();

		$this->assertStringNotContainsString( 'mwtfrs-guest__captcha', $html );
		$this->assertFalse( wp_script_is( Turnstile::SCRIPT, 'enqueued' ) );
	}

	public function test_without_the_check_the_form_has_no_widget(): void {
		Settings::update( Settings::TURNSTILE_SECRET_KEY, '' );

		$this->assertStringNotContainsString( 'mwtfrs-guest__captcha', $this->render_board() );
		$this->assertFalse( wp_script_is( Turnstile::SCRIPT, 'enqueued' ) );
	}

	public function test_a_link_request_without_the_widgets_answer_is_refused_and_says_why(): void {
		$input = [
			'request_id'           => $this->request,
			'redirect_to'          => home_url( '/board/' ),
			'email'                => 'jane@example.com',
			SpamGuard::FIELD_TRAP  => '',
			SpamGuard::FIELD_TOKEN => SpamGuard::token( time() - 30 ),
		];

		$url = GuestLinkForm::process( $input, '', home_url( '/board/' ) );

		$this->assertStringContainsString( RequestNotice::PARAM . '=link-captcha', $url );
		$this->assertSame( [], $this->sent_emails() );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
		$this->assertStringContainsString( 'The spam check next to the button did not pass', $this->render_board() );
		$_GET = [];
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
}
