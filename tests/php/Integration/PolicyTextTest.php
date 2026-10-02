<?php
/**
 * Suggested privacy policy text tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use Akismet;
use FeatureRequestSystem\Privacy\PolicyText;
use FeatureRequestSystem\Voting\VoterIdentity;
use WP_Privacy_Policy_Content;

/**
 * The text that WordPress's privacy policy guide offers site owners for this plugin.
 */
final class PolicyTextTest extends TestCase {

	/**
	 * Whether the test marked admin_init as done itself.
	 *
	 * @var bool
	 */
	private $counted_admin_init = false;

	public function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__ ) . '/Fakes/Akismet.php';
	}

	public function tear_down(): void {
		Akismet::reset();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_the_text_says_what_the_plugin_stores(): void {
		$text = PolicyText::html();

		$this->assertStringContainsString( 'We store them with your request. Only our team can see them', $text );
		$this->assertStringContainsString( 'a cookie named ' . VoterIdentity::COOKIE . ' in your browser for one year', $text );
		$this->assertStringContainsString( 'we store that you follow that request, and we email you when its status changes', $text );
		$this->assertStringContainsString( 'We do not store your IP address.', $text );
		$this->assertSame( 1, substr_count( $text, '<strong class="privacy-policy-tutorial">Suggested text: </strong>' ) );
		$this->assertStringStartsWith( '<p class="privacy-policy-tutorial">', $text, 'A note for the site owner comes first. WordPress leaves it out when the text is copied.' );
	}

	public function test_akismet_is_only_mentioned_when_it_checks_new_requests(): void {
		$this->assertStringNotContainsString( 'Akismet', PolicyText::html() );

		Akismet::$api_key = 'key';

		$this->assertStringContainsString( 'New feature requests are checked by Akismet', PolicyText::html() );
		$this->assertStringContainsString( '<a href="https://akismet.com/privacy/">privacy policy</a>', PolicyText::html() );
	}

	public function test_a_translation_can_add_a_link_to_the_text_and_no_other_markup(): void {
		add_filter(
			'gettext',
			static function ( string $translation, string $text ): string {
				return 0 === strpos( $text, 'When you vote while you are logged in' )
					? $translation . ' <script>alert(1)</script><a href="https://example.org/cookies" onclick="steal()">More about cookies</a>'
					: $translation;
			},
			10,
			2
		);

		$text = PolicyText::html();

		$this->assertStringContainsString( '<a href="https://example.org/cookies">More about cookies</a>', $text );
		$this->assertStringNotContainsString( '<script', $text );
		$this->assertStringNotContainsString( 'onclick', $text );
	}

	public function test_the_text_reaches_wordpresss_privacy_policy_guide(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		$this->assertSame( 10, has_action( 'admin_init', [ PolicyText::class, 'add' ] ) );

		// The guide only takes text in the admin, from admin_init on.
		set_current_screen( 'dashboard' );
		if ( ! did_action( 'admin_init' ) ) {
			// Counted as done without running every plugin's and WordPress's own admin_init callbacks.
			$GLOBALS['wp_actions']['admin_init'] = 1;
			$this->counted_admin_init          = true;
		}
		PolicyText::add();
		if ( $this->counted_admin_init ) {
			unset( $GLOBALS['wp_actions']['admin_init'] );
		}

		$ours = wp_list_filter( WP_Privacy_Policy_Content::get_suggested_policy_text(), [ 'plugin_name' => 'Feature Request System' ] );

		$this->assertCount( 1, $ours );
		$this->assertSame( PolicyText::html(), array_values( $ours )[0]['policy_text'] );
	}
}
