<?php
/**
 * Submission form rendering tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\CurrentPage;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Submission\SpamGuard;
use WP_Block_Patterns_Registry;

/**
 * Renders the real form block, the way a page does.
 */
final class SubmitFormRenderTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
	}

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	public function test_logged_in_users_get_the_form_with_a_nonce_and_a_token(): void {
		wp_set_current_user( self::factory()->user->create() );

		$html = $this->render();

		$this->assertStringContainsString( 'action="' . admin_url( 'admin-post.php' ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . SubmitForm::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="board" value="' . $this->default_board() . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( SubmitForm::ACTION ) . '"', $html );
		$this->assertMatchesRegularExpression( '/name="' . SpamGuard::FIELD_TOKEN . '" value="\d+\.[0-9a-f]{64}"/', $html );
		$this->assertStringContainsString( 'name="title"', $html );
		$this->assertStringContainsString( 'name="' . SpamGuard::FIELD_TRAP . '"', $html );
		$this->assertStringNotContainsString( 'name="email"', $html, 'Logged-in users are not asked who they are.' );
		$this->assertStringNotContainsString( 'About you', $html );
	}

	public function test_guests_are_asked_to_log_in_by_default(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'Log in to suggest a feature.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( wp_login_url( CurrentPage::url() ) ) . '"', $html );
	}

	public function test_guests_on_open_boards_get_name_and_email_fields_but_no_nonce(): void {
		$this->allow_guests();

		$html = $this->render();

		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringNotContainsString( '_wpnonce', $html );
		$this->assertSame( '', wp_interactivity_state( SubmitForm::BLOCK )['nonce'], 'The script state carries no nonce either.' );
	}

	public function test_guests_are_told_who_sees_their_name_and_email(): void {
		$this->allow_guests();

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<legend[^>]*>\s*About you\s*<span[^>]*>\(optional\)<\/span>\s*<\/legend>/', $html );
		$this->assertStringContainsString( 'Only our team sees this, in case we have a question about your request.', $html );
		$this->assertSame(
			2,
			preg_match_all( '/name="(name|email)"[^>]*aria-describedby="mwtfrs-submit-\d+-about-help"/', $html ),
			'Both fields point screen readers at the note.'
		);
	}

	public function test_every_guest_gets_the_same_html_so_pages_can_be_cached(): void {
		$this->allow_guests();

		$this->assertSame( $this->normalize( $this->render() ), $this->normalize( $this->render() ) );
	}

	public function test_boards_that_stopped_taking_requests_say_so(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::NOBODY ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'This board is not taking new requests.', $html );
	}

	public function test_categories_can_be_chosen_from_a_list(): void {
		$category = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Reports & exports',
			]
		);
		wp_set_current_user( self::factory()->user->create() );

		$this->assertStringContainsString( '<option value="' . $category . '">Reports &amp; exports</option>', $this->render() );
	}

	public function test_result_of_a_post_without_javascript_is_shown_in_the_form(): void {
		wp_set_current_user( self::factory()->user->create() );
		$_GET[ SubmitForm::PARAM_NOTICE ] = 'pending';

		$html = $this->render();

		$this->assertStringContainsString( '<span data-wp-text="context.message">Thanks! Your request will appear on the board once it is approved.</span>', $html );
		$this->assertStringContainsString( 'class="mwtfrs-submit__message"', $html );

		$_GET[ SubmitForm::PARAM_NOTICE ] = 'invalid-title';
		$html                             = $this->render();
		$this->assertStringContainsString( '<span data-wp-text="context.message">Add a title for your request.</span>', $html );
		$this->assertStringContainsString( 'class="mwtfrs-submit__message is-error"', $html );
	}

	public function test_form_has_an_anchor_to_return_to_after_a_post(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertStringContainsString( 'id="' . SubmitForm::anchor( $this->default_board() ) . '"', $this->render() );
	}

	public function test_shortcode_renders_the_form_for_the_chosen_board(): void {
		$board = self::factory()->term->create(
			[
				'taxonomy' => BoardTaxonomy::NAME,
				'slug'     => 'mobile-app',
			]
		);
		wp_set_current_user( self::factory()->user->create() );

		$html = do_shortcode( '[' . Blocks::FORM_SHORTCODE . ' board="mobile-app"]' );

		$this->assertStringContainsString( 'name="board" value="' . $board . '"', $html );
		$this->assertMatchesRegularExpression( '/class="[^"]*\bwp-block-mwtfrs-submit\b/', $html );
	}

	public function test_a_page_pattern_puts_the_form_above_the_board(): void {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN );
		$blocks  = array_values( array_filter( wp_list_pluck( parse_blocks( $pattern['content'] ), 'blockName' ) ) );

		$this->assertSame( [ SubmitForm::BLOCK, 'core/heading', Board::BLOCK ], $blocks );
	}

	/**
	 * Renders the form block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render( array $attributes = [] ): string {
		return render_block(
			[
				'blockName'    => SubmitForm::BLOCK,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
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
	 * Lets guests submit to the default board.
	 */
	private function allow_guests(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
	}
}
