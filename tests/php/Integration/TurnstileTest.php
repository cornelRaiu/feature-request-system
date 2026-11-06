<?php
/**
 * Cloudflare Turnstile tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\SettingsPage;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Privacy\PolicyText;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\DataUseNote;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\Turnstile;
use FeatureRequestSystem\Voting\VoterIdentity;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Turnstile widget on the form, its check of submissions, and its keys on the settings page.
 * Cloudflare is never called: its siteverify endpoint is faked with pre_http_request.
 */
final class TurnstileTest extends TestCase {

	private const SITE_KEY = '0x4AAAAAAAsitekeyE2E';

	private const SECRET = '0x4AAAAAAAsecretOfThisSiteOnly';

	private const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Token that the fake siteverify confirms.
	 */
	private const GOOD_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

	/**
	 * Requests made to siteverify: their URL and body.
	 *
	 * @var array<int, array{url: string, body: mixed}>
	 */
	private $calls = [];

	/**
	 * What the fake siteverify answers: a response array, or a WP_Error for a network failure.
	 *
	 * @var array<string, mixed>|WP_Error|null
	 */
	private $answer = null;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		$this->calls            = [];
		$this->answer           = null;
		add_filter( 'pre_http_request', [ $this, 'fake_siteverify' ], 10, 3 );
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
		wp_dequeue_script( Turnstile::SCRIPT );
		wp_deregister_script( Turnstile::SCRIPT );
	}

	public function tear_down(): void {
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		wp_dequeue_script( Turnstile::SCRIPT );
		wp_deregister_script( Turnstile::SCRIPT );
		unregister_setting( SettingsPage::GROUP, Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Answers requests to Cloudflare's siteverify, as Cloudflare would, and lets every other request through.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Response so far.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @param string                              $url     Request URL.
	 * @return false|array<string, mixed>|WP_Error
	 */
	public function fake_siteverify( $preempt, array $args, string $url ) {
		if ( 0 !== strpos( $url, 'https://challenges.cloudflare.com/' ) ) {
			return $preempt;
		}

		$this->calls[] = [
			'url'  => $url,
			'body' => $args['body'] ?? null,
		];
		if ( null !== $this->answer ) {
			return $this->answer;
		}

		$body = is_array( $args['body'] ?? null ) ? $args['body'] : [];
		$good = self::SECRET === ( $body['secret'] ?? '' ) && self::GOOD_TOKEN === ( $body['response'] ?? '' );

		return $this->response( 200, $good ? [ 'success' => true ] : [ 'success' => false, 'error-codes' => [ 'invalid-input-response' ] ] );
	}

	public function test_a_guest_without_a_token_is_refused_and_told_to_complete_the_check(): void {
		$this->save_keys();

		foreach ( [ [], [ Turnstile::FIELD => '' ] ] as $fields ) {
			$response = $this->submit( $fields );

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( Turnstile::ERROR_FAILED, $response->get_data()['code'] );
			$this->assertSame( 'Complete the spam check above the button, then send your request again.', $response->get_data()['message'] );
		}
		$this->assertSame( 0, $this->count_requests() );
		$this->assertSame( [], $this->calls, 'Without a token there is nothing to ask Cloudflare.' );
	}

	public function test_a_guest_with_a_token_cloudflare_confirms_is_accepted(): void {
		$this->save_keys();

		$response = $this->submit( [ Turnstile::FIELD => self::GOOD_TOKEN ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, $this->count_requests() );
		$this->assertSame(
			[
				[
					'url'  => self::SITEVERIFY,
					'body' => [
						'secret'   => self::SECRET,
						'response' => self::GOOD_TOKEN,
					],
				],
			],
			$this->calls,
			'One check, with the secret and the token, and nothing else about the visitor.'
		);
	}

	public function test_a_token_cloudflare_does_not_confirm_is_refused(): void {
		$this->save_keys();

		$response = $this->submit( [ Turnstile::FIELD => 'forged' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Turnstile::ERROR_FAILED, $response->get_data()['code'] );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_without_an_answer_from_cloudflare_the_submission_is_refused(): void {
		$this->save_keys();
		$answers = [
			'network failure'   => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
			'server error'      => $this->response( 500, [ 'success' => true ] ),
			'not json'          => $this->response( 200, null, '<html>Bad gateway</html>' ),
			'success not true'  => $this->response( 200, [ 'success' => 'yes' ] ),
			'no success at all' => $this->response( 200, [ 'error-codes' => [] ] ),
		];

		foreach ( $answers as $case => $answer ) {
			$this->answer = $answer;

			$response = $this->submit( [ Turnstile::FIELD => self::GOOD_TOKEN ] );

			$this->assertSame( 400, $response->get_status(), $case );
			$this->assertSame( Turnstile::ERROR_FAILED, $response->get_data()['code'], $case );
		}
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_a_token_longer_than_cloudflare_issues_is_refused_without_asking(): void {
		$this->save_keys();

		$response = $this->submit( [ Turnstile::FIELD => str_repeat( 'a', 2049 ) ] );

		$this->assertSame( Turnstile::ERROR_FAILED, $response->get_data()['code'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_bots_the_cheap_checks_catch_never_reach_cloudflare(): void {
		$this->save_keys();

		$response = $this->submit(
			[
				SpamGuard::FIELD_TRAP => 'https://spam.example',
				Turnstile::FIELD      => self::GOOD_TOKEN,
			]
		);

		$this->assertSame( SpamGuard::ERROR_REJECTED, $response->get_data()['code'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_the_check_is_off_until_both_keys_are_saved(): void {
		foreach ( [ [ self::SITE_KEY, '' ], [ '', self::SECRET ] ] as list( $site_key, $secret ) ) {
			Settings::update( Settings::TURNSTILE_SITE_KEY, $site_key );
			Settings::update( Settings::TURNSTILE_SECRET_KEY, $secret );

			$this->assertFalse( Turnstile::is_active() );
			$this->assertSame( 201, $this->submit()->get_status() );
			$this->assertStringNotContainsString( 'cf-turnstile', $this->form() );
			$this->assertFalse( wp_script_is( Turnstile::SCRIPT, 'enqueued' ), 'Nothing is loaded from Cloudflare.' );
		}
		$this->assertSame( [], $this->calls );
	}

	public function test_logged_in_users_are_checked_only_when_the_site_says_so(): void {
		$this->save_keys();
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( 201, $this->submit()->get_status() );
		$this->assertStringNotContainsString( 'cf-turnstile', $this->form() );
		$this->assertFalse( wp_script_is( Turnstile::SCRIPT, 'enqueued' ) );

		Settings::update( Settings::TURNSTILE_LOGGED_IN, true );

		$this->assertSame( Turnstile::ERROR_FAILED, $this->submit()->get_data()['code'] );
		$this->assertSame( 201, $this->submit( [ Turnstile::FIELD => self::GOOD_TOKEN ] )->get_status() );
		$this->assertStringContainsString( 'class="cf-turnstile"', $this->form() );
		$this->assertTrue( wp_script_is( Turnstile::SCRIPT, 'enqueued' ) );
	}

	public function test_the_form_shows_the_widget_to_guests_and_loads_cloudflares_script(): void {
		$this->save_keys();

		$html = $this->form();

		$this->assertStringContainsString( '<div class="cf-turnstile" data-sitekey="' . self::SITE_KEY . '" data-response-field-name="' . Turnstile::FIELD . '" data-size="flexible"></div>', $html );
		$this->assertMatchesRegularExpression( '/<noscript><p[^>]*>The spam check of this form needs JavaScript\. Turn it on to send your request\.<\/p><\/noscript>/', $html );
		$this->assertLessThan( strpos( $html, 'type="submit"' ), strpos( $html, 'cf-turnstile' ), 'The widget is above the button.' );

		$script = wp_scripts()->query( Turnstile::SCRIPT );
		$this->assertTrue( wp_script_is( Turnstile::SCRIPT, 'enqueued' ) );
		$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/api.js', $script->src );
		$this->assertNull( $script->ver, 'Cloudflare\'s script is loaded as it is, without a version.' );

		$this->assertSame( $this->normalize( $html ), $this->normalize( $this->form() ), 'Every guest gets the same HTML, so pages can be cached.' );

		// WordPress starts the list of inline scripts with a false.
		$before = array_values( array_filter( (array) wp_scripts()->get_data( Turnstile::SCRIPT, 'before' ) ) );
		$this->assertCount( 1, $before, 'Two forms on a page add the script once.' );
		$this->assertStringContainsString( 'if(w.clientWidth<300){w.setAttribute("data-size","compact");}', $before[0], 'A form narrower than the widget gets the compact one.' );
	}

	public function test_a_board_that_takes_no_requests_loads_nothing_from_cloudflare(): void {
		$this->save_keys();
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::LOGGED_IN ] );

		$this->assertStringNotContainsString( 'cf-turnstile', $this->form() );
		$this->assertFalse( wp_script_is( Turnstile::SCRIPT, 'enqueued' ) );
	}

	public function test_the_form_post_without_javascript_is_refused_while_the_check_is_on(): void {
		$this->save_keys();
		$input = array_merge(
			$this->submission(),
			[
				'action'      => SubmitForm::ACTION,
				'board'       => (string) $this->default_board(),
				'redirect_to' => 'http://example.org/roadmap/',
			]
		);

		$url = SubmitForm::process( $input, home_url(), '' );
		$this->assertStringContainsString( 'fr-form=captcha', $url );
		$this->assertSame( 0, $this->count_requests() );

		$_GET[ SubmitForm::PARAM_NOTICE ] = 'captcha';
		$this->assertSame( 'Complete the spam check above the button, then send your request again.', SubmitForm::notice_from_request()['message'] );

		$this->assertStringContainsString( 'fr-form=pending', SubmitForm::process( array_merge( $input, [ Turnstile::FIELD => self::GOOD_TOKEN ] ), home_url(), '' ) );
		$this->assertSame( 1, $this->count_requests() );
	}

	public function test_the_secret_key_is_never_printed_back(): void {
		$this->save_keys();

		$page = $this->settings_page();
		$this->assertStringNotContainsString( self::SECRET, $page );
		$this->assertStringContainsString( 'name="' . Settings::OPTION . '[' . Settings::TURNSTILE_SECRET_KEY . ']" value="" autocomplete="new-password" spellcheck="false" placeholder="Saved">', $page );
		$this->assertStringContainsString( 'A secret key is saved. It is not shown again.', $page );
		$this->assertStringContainsString( 'value="' . self::SITE_KEY . '"', $page, 'The site key is public and shown.' );
		$this->assertStringContainsString( 'Remove the saved secret key', $page );

		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
		$this->assertStringNotContainsString( self::SECRET, $this->form() );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( wp_interactivity_state( SubmitForm::BLOCK ) ) );
		$this->assertStringNotContainsString( self::SECRET, PolicyText::html() . DataUseNote::suggestion() );
	}

	public function test_an_empty_secret_field_keeps_the_saved_key_and_the_box_removes_it(): void {
		SettingsPage::register();
		$sent = [
			Settings::TURNSTILE_SITE_KEY   => '  ' . self::SITE_KEY . ' ',
			Settings::TURNSTILE_SECRET_KEY => self::SECRET,
			Settings::TURNSTILE_LOGGED_IN  => '1',
		];

		update_option( Settings::OPTION, $sent );
		$this->assertSame( self::SITE_KEY, Settings::get( Settings::TURNSTILE_SITE_KEY ) );
		$this->assertSame( self::SECRET, Settings::get( Settings::TURNSTILE_SECRET_KEY ) );
		$this->assertTrue( Settings::get( Settings::TURNSTILE_LOGGED_IN ) );

		// The form sends the field empty, because the saved key is never printed in it.
		update_option( Settings::OPTION, array_merge( $sent, [ Settings::TURNSTILE_SECRET_KEY => '' ] ) );
		$this->assertSame( self::SECRET, Settings::get( Settings::TURNSTILE_SECRET_KEY ) );

		update_option( Settings::OPTION, array_merge( $sent, [ Settings::TURNSTILE_SECRET_KEY => 'new-secret' ] ) );
		$this->assertSame( 'new-secret', Settings::get( Settings::TURNSTILE_SECRET_KEY ) );

		update_option(
			Settings::OPTION,
			array_merge(
				$sent,
				[
					Settings::TURNSTILE_SECRET_KEY => '',
					'turnstile_forget_secret'      => '1',
				]
			)
		);
		$this->assertSame( '', Settings::get( Settings::TURNSTILE_SECRET_KEY ) );
		$this->assertArrayNotHasKey( 'turnstile_forget_secret', get_option( Settings::OPTION ), 'The box is not a setting.' );
		$this->assertFalse( Turnstile::is_active() );

		$page = $this->settings_page();
		$this->assertStringNotContainsString( 'placeholder="Saved"', $page );
		$this->assertStringNotContainsString( 'Remove the saved secret key', $page, 'Nothing to remove.' );
	}

	public function test_the_privacy_texts_name_cloudflare_only_while_the_check_is_on(): void {
		$this->assertStringNotContainsString( 'Cloudflare', PolicyText::html() );
		$this->assertStringNotContainsString( 'Cloudflare', DataUseNote::suggestion() );

		$this->save_keys();

		$this->assertStringContainsString( 'protected by Cloudflare Turnstile', PolicyText::html() );
		$this->assertStringContainsString( '<a href="https://www.cloudflare.com/turnstile-privacy-policy/">privacy policy for Turnstile</a>', PolicyText::html() );
		$this->assertStringContainsString( 'the form is checked by Cloudflare Turnstile', DataUseNote::suggestion() );
	}

	/**
	 * Saves both keys of a widget.
	 */
	private function save_keys(): void {
		Settings::update( Settings::TURNSTILE_SITE_KEY, self::SITE_KEY );
		Settings::update( Settings::TURNSTILE_SECRET_KEY, self::SECRET );
	}

	/**
	 * A response as the HTTP API returns it.
	 *
	 * @param int        $code HTTP status.
	 * @param mixed      $json Body, encoded as JSON.
	 * @param string|null $raw Body as it is, instead.
	 * @return array<string, mixed>
	 */
	private function response( int $code, $json, ?string $raw = null ): array {
		return [
			'headers'  => [],
			'body'     => $raw ?? (string) wp_json_encode( $json ),
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * Sends a submission through the REST server, from a page of this site.
	 *
	 * @param array<string, mixed> $fields Fields to override in a valid submission.
	 */
	private function submit( array $fields = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/boards/' . $this->default_board() . '/requests' );
		$request->set_body_params( $this->submission( $fields ) );
		$request->set_header( 'Origin', home_url() );
		// Each submission is a new visitor, so the rate limit does not get in the way.
		$_COOKIE[ VoterIdentity::COOKIE ] = bin2hex( random_bytes( 16 ) );
		$_SERVER['REMOTE_ADDR']           = '203.0.113.' . wp_rand( 1, 250 );

		return rest_do_request( $request );
	}

	/**
	 * Renders the form block.
	 */
	private function form(): string {
		return render_block(
			[
				'blockName'    => SubmitForm::BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Returns the fields of the settings page.
	 */
	private function settings_page(): string {
		SettingsPage::register();
		ob_start();
		do_settings_sections( SettingsPage::SLUG );

		return (string) ob_get_clean();
	}

	/**
	 * Removes what legitimately differs between two renders: generated IDs and the token's time.
	 *
	 * @param string $html Form HTML.
	 */
	private function normalize( string $html ): string {
		$html = (string) preg_replace( '/mwtfrs-submit-\d+-/', 'mwtfrs-submit-N-', $html );

		return (string) preg_replace( '/name="' . SpamGuard::FIELD_TOKEN . '" value="[^"]*"/', 'name="' . SpamGuard::FIELD_TOKEN . '" value="T"', $html );
	}

	/**
	 * Counts requests in any status.
	 */
	private function count_requests(): int {
		return count(
			get_posts(
				[
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}
}
