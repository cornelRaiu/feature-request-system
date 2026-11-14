<?php
/**
 * Tests for the question asked when the plugin is deactivated.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\DeactivationFeedback;
use FeatureRequestSystem\Plugin;
use WPDieException;

/**
 * The dialog on the Plugins screen, and what the site sends when an answer is chosen.
 */
final class DeactivationFeedbackTest extends TestCase {

	/**
	 * Requests the site made to other servers, with their URL.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private $sent = [];

	public function set_up(): void {
		parent::set_up();
		$this->sent = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				$this->sent[] = [
					'url'  => $url,
					'args' => $args,
				];
				return [
					'headers'  => [],
					'body'     => '{"ok":true}',
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		$_POST    = [];
		$_REQUEST = [];
		set_current_screen( 'front' );
		wp_dequeue_script( 'mwtfrs-deactivation-feedback' );
		wp_deregister_script( 'mwtfrs-deactivation-feedback' );
		parent::tear_down();
	}

	public function test_admins_get_the_dialog_on_the_plugins_screen_only(): void {
		wp_set_current_user( $this->admin() );

		set_current_screen( 'plugins' );
		$html = $this->render();
		$this->assertStringContainsString( '<dialog id="mwtfrs-df"', $html );
		foreach ( [ 'stopped-working', 'missing-feature', 'better-plugin', 'no-longer-needed', 'broke-site', 'temporary', 'other' ] as $reason ) {
			$this->assertStringContainsString( 'value="' . $reason . '"', $html, $reason );
		}
		$this->assertStringContainsString( DeactivationFeedback::SUPPORT_URL, $html, 'The answers about the plugin not working lead to the forum.' );
		$this->assertStringContainsString( DeactivationFeedback::PRIVACY_URL, $html );

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->render(), 'Nothing on other screens.' );

		set_current_screen( 'plugins' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '', $this->render(), 'Nothing for users who cannot deactivate plugins.' );
	}

	public function test_the_script_loads_on_the_plugins_screen_with_the_plugin_s_row(): void {
		wp_set_current_user( $this->admin() );

		DeactivationFeedback::enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'mwtfrs-deactivation-feedback' ), 'Not on other screens.' );

		DeactivationFeedback::enqueue( 'plugins.php' );
		$this->assertTrue( wp_script_is( 'mwtfrs-deactivation-feedback' ) );
		$this->assertTrue( wp_style_is( 'mwtfrs-deactivation-feedback' ) );

		$data = implode( '', wp_scripts()->get_data( 'mwtfrs-deactivation-feedback', 'before' ) );
		$this->assertStringContainsString( wp_json_encode( plugin_basename( Plugin::path( 'feature-request-system.php' ) ) ), $data );
		$this->assertStringContainsString( wp_create_nonce( DeactivationFeedback::ACTION ), $data );
	}

	public function test_a_sent_answer_goes_to_master_webtools_without_the_site_address(): void {
		wp_set_current_user( $this->admin() );

		$reply = $this->answer(
			[
				'reason'  => 'better-plugin',
				'details' => '  Canny  ',
			]
		);

		$this->assertTrue( $reply['success'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( DeactivationFeedback::FEEDBACK_URL, $this->sent[0]['url'] );
		$this->assertSame( 'FeatureRequestSystem/' . Plugin::VERSION, $this->sent[0]['args']['user-agent'], 'WordPress\'s own user agent would name the site.' );
		$this->assertLessThanOrEqual( 3, $this->sent[0]['args']['timeout'] );

		$body = json_decode( $this->sent[0]['args']['body'], true );
		$this->assertSame(
			[
				'plugin'         => 'feature-request-system',
				'reason'         => 'better-plugin',
				'details'        => 'Canny',
				'plugin_version' => Plugin::VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
				'locale'         => get_locale(),
			],
			$body
		);
		$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $this->sent[0]['args']['body'] );
	}

	public function test_text_is_cleaned_and_cut_to_a_thousand_characters(): void {
		wp_set_current_user( $this->admin() );

		$this->answer(
			[
				'reason'  => 'other',
				'details' => '<b>Too</b> ' . str_repeat( 'é', 1200 ),
			]
		);

		$details = json_decode( $this->sent[0]['args']['body'], true )['details'];
		$this->assertSame( 1000, mb_strlen( $details ) );
		$this->assertStringStartsWith( 'Too é', $details, 'Tags are removed.' );
	}

	public function test_answers_that_are_not_sent(): void {
		wp_set_current_user( $this->admin() );

		$this->assertFalse( $this->answer( [ 'reason' => 'other' ] )['success'], '"Other" with nothing typed.' );
		$this->assertFalse( $this->answer( [ 'reason' => 'something-else' ] )['success'], 'An answer the form doesn\'t offer.' );
		$this->assertFalse( $this->answer( [] )['success'], 'No answer.' );
		$this->assertSame( [], $this->sent );
	}

	public function test_temporary_sends_nothing_and_can_put_the_question_off_for_a_day(): void {
		$user = $this->admin();
		wp_set_current_user( $user );

		$this->assertTrue( $this->answer( [ 'reason' => 'temporary' ] )['success'] );
		$this->assertSame( '', get_user_meta( $user, DeactivationFeedback::META_SNOOZE, true ), 'Without the box ticked, the question comes back.' );

		$this->answer(
			[
				'reason' => 'temporary',
				'snooze' => '1',
			]
		);
		$until = (int) get_user_meta( $user, DeactivationFeedback::META_SNOOZE, true );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $until, 5 );
		$this->assertSame( [], $this->sent );

		set_current_screen( 'plugins' );
		$this->assertSame( '', $this->render(), 'No dialog while the question is put off.' );
		DeactivationFeedback::enqueue( 'plugins.php' );
		$this->assertFalse( wp_script_is( 'mwtfrs-deactivation-feedback' ) );

		update_user_meta( $user, DeactivationFeedback::META_SNOOZE, time() - 1 );
		$this->assertStringContainsString( '<dialog', $this->render(), 'The question comes back after the day.' );
	}

	public function test_answers_need_the_nonce_and_a_user_who_can_deactivate_plugins(): void {
		wp_set_current_user( $this->admin() );
		$this->assertSame( 403, $this->answer( [ 'reason' => 'no-longer-needed' ], 'not-a-nonce' )['status'] );

		// On the command line headers are already sent, so WordPress skips the status here; the refusal is the error.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( $this->answer( [ 'reason' => 'no-longer-needed' ] )['success'] );

		$this->assertSame( [], $this->sent );
	}

	/**
	 * Creates a user who may deactivate plugins: an administrator, who on a network must
	 * also be a network administrator.
	 */
	private function admin(): int {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		return $user;
	}

	/**
	 * Prints the dialog and returns it.
	 */
	private function render(): string {
		ob_start();
		DeactivationFeedback::render();
		return (string) ob_get_clean();
	}

	/**
	 * Sends an answer to the admin-ajax handler as the current user.
	 *
	 * @param array<string, string> $fields Posted fields.
	 * @param string|null           $nonce  Nonce to send, or null for a valid one.
	 * @return array{success: bool, status: int}
	 */
	private function answer( array $fields, ?string $nonce = null ): array {
		$_POST                = $fields + [ '_ajax_nonce' => $nonce ?? wp_create_nonce( DeactivationFeedback::ACTION ) ];
		$_REQUEST             = $_POST;
		$status               = 200;
		$status_filter        = static function ( $header, $code ) use ( &$status ) {
			$status = $code;
			return $header;
		};
		$die_handler          = static function () use ( &$status ) {
			return static function ( $message = '', $title = '', $args = [] ) use ( &$status ) {
				if ( is_array( $args ) && ! empty( $args['response'] ) ) {
					$status = (int) $args['response'];
				}
				throw new WPDieException( is_scalar( $message ) ? (string) $message : '' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'status_header', $status_filter, 10, 2 );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		ob_start();
		try {
			DeactivationFeedback::handle_answer();
		} catch ( WPDieException $stopped ) {
			unset( $stopped );
		}
		$output = (string) ob_get_clean();

		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'status_header', $status_filter, 10 );
		remove_filter( 'wp_die_ajax_handler', $die_handler );

		$json = json_decode( $output, true );

		return [
			'success' => is_array( $json ) && ! empty( $json['success'] ),
			'status'  => $status,
		];
	}
}
