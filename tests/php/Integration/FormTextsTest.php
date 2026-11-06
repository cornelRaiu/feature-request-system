<?php
/**
 * Form text tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\SubmitForm;

/**
 * The texts a site can change on the form, in the block and in the shortcode.
 */
final class FormTextsTest extends TestCase {

	/**
	 * Texts of a form that a site changed, by block attribute.
	 */
	private const CUSTOM = [
		'heading'          => 'Tell us what to build',
		'titleLabel'       => 'Your idea',
		'titleHelp'        => 'In a few words.',
		'detailsLabel'     => 'Tell us more',
		'submitLabel'      => 'Send idea',
		'pendingMessage'   => 'Got it! We read every idea before it goes up.',
		'publishedMessage' => 'Got it! Your idea is up.',
	];

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		wp_set_current_user( self::factory()->user->create() );
	}

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	public function test_a_form_without_texts_of_its_own_shows_the_defaults(): void {
		foreach ( [ [], array_fill_keys( array_keys( self::CUSTOM ), '' ), array_fill_keys( array_keys( self::CUSTOM ), "  \n " ) ] as $attributes ) {
			$html = $this->render( $attributes );

			$this->assertMatchesRegularExpression( '/<h2 class="mwtfrs-submit__heading"[^>]*>Suggest a feature<\/h2>/', $html );
			$this->assertMatchesRegularExpression( '/<label for="mwtfrs-submit-\d+-title">Title<\/label>/', $html );
			$this->assertStringContainsString( '-title-help">One short sentence, such as &quot;Export requests as CSV&quot;.</span>', $html );
			$this->assertMatchesRegularExpression( '/-description">\s*Details\s*<span class="mwtfrs-submit__optional">/', $html );
			$this->assertMatchesRegularExpression( '/data-wp-bind--disabled="context.busy">Send request<\/button>/', $html );
			$this->assertSame(
				[
					'pending' => 'Thanks! Your request will appear on the board once it is approved.',
					'publish' => 'Thanks! Your request is on the board.',
				],
				$this->context( $html )['messages']
			);
		}
	}

	public function test_the_block_shows_its_own_texts(): void {
		$html = $this->render( self::CUSTOM );

		$this->assertMatchesRegularExpression( '/<h2 class="mwtfrs-submit__heading"[^>]*>Tell us what to build<\/h2>/', $html );
		$this->assertMatchesRegularExpression( '/<label for="mwtfrs-submit-\d+-title">Your idea<\/label>/', $html );
		$this->assertStringContainsString( '-title-help">In a few words.</span>', $html );
		$this->assertMatchesRegularExpression( '/-description">\s*Tell us more\s*<span class="mwtfrs-submit__optional">\(optional\)/', $html );
		$this->assertStringContainsString( 'data-wp-bind--disabled="context.busy">Send idea</button>', $html );
		$this->assertSame(
			[
				'pending' => 'Got it! We read every idea before it goes up.',
				'publish' => 'Got it! Your idea is up.',
			],
			$this->context( $html )['messages'],
			'The script shows the form\'s own message after a request is sent.'
		);
		foreach ( [ 'Suggest a feature', '>Title<', 'One short sentence', '>Send request<' ] as $default ) {
			$this->assertStringNotContainsString( $default, $html, $default );
		}
	}

	public function test_the_heading_stays_where_the_board_takes_no_requests_or_asks_to_log_in(): void {
		$this->assertStringContainsString( '>Tell us what to build</h2>', $this->render( self::CUSTOM ) );

		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::NOBODY ] );
		$this->assertStringContainsString( '>Tell us what to build</h2>', $this->render( self::CUSTOM ) );

		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::LOGGED_IN ] );
		wp_set_current_user( 0 );
		$html = $this->render( self::CUSTOM );
		$this->assertStringContainsString( '>Tell us what to build</h2>', $html );
		$this->assertStringContainsString( 'Log in to suggest a feature.', $html );
	}

	public function test_after_a_post_without_javascript_the_form_says_its_own_message(): void {
		$_GET[ SubmitForm::PARAM_NOTICE ] = 'pending';
		$this->assertStringContainsString( '<span data-wp-text="context.message">Got it! We read every idea before it goes up.</span>', $this->render( self::CUSTOM ) );
		$this->assertStringContainsString( '<span data-wp-text="context.message">Thanks! Your request will appear on the board once it is approved.</span>', $this->render() );

		$_GET[ SubmitForm::PARAM_NOTICE ] = 'published';
		$this->assertStringContainsString( '<span data-wp-text="context.message">Got it! Your idea is up.</span>', $this->render( self::CUSTOM ) );
		$this->assertStringContainsString( '<span data-wp-text="context.message">Thanks! Your request is on the board.</span>', $this->render() );

		$_GET[ SubmitForm::PARAM_NOTICE ] = 'invalid-title';
		$this->assertStringContainsString( '<span data-wp-text="context.message">Add a title for your request.</span>', $this->render( self::CUSTOM ), 'Errors keep their own messages.' );
	}

	public function test_texts_are_plain_text(): void {
		$html = $this->render(
			[
				'heading'        => 'Ideas <em>welcome</em> & thanks',
				'submitLabel'    => '<img src=x onerror=alert(1)>',
				'titleHelp'      => '"><script>alert(1)</script>',
				'pendingMessage' => '<b>Bold</b>',
			]
		);

		$this->assertStringContainsString( '>Ideas &lt;em&gt;welcome&lt;/em&gt; &amp; thanks</h2>', $html );
		$this->assertStringContainsString( '>&lt;img src=x onerror=alert(1)&gt;</button>', $html );
		$this->assertStringContainsString( '-title-help">&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;</span>', $html );
		$this->assertSame( '<b>Bold</b>', $this->context( $html )['messages']['pending'], 'The script writes the message as text.' );
		foreach ( [ '<em>', '<img', '<script', '<b>' ] as $tag ) {
			$this->assertStringNotContainsString( $tag, $html, $tag );
		}
	}

	public function test_the_shortcode_takes_the_same_texts(): void {
		$html = do_shortcode( '[' . Blocks::FORM_SHORTCODE . ' heading="Tell us what to build" title_label="Your idea" title_help="In a few words." details_label="Tell us more" submit_label="Send idea" pending_message="Got it! We read every idea." published_message="Got it! Your idea is up."]' );

		$this->assertStringContainsString( '>Tell us what to build</h2>', $html );
		$this->assertMatchesRegularExpression( '/<label for="mwtfrs-submit-\d+-title">Your idea<\/label>/', $html );
		$this->assertStringContainsString( '-title-help">In a few words.</span>', $html );
		$this->assertMatchesRegularExpression( '/-description">\s*Tell us more\s*</', $html );
		$this->assertStringContainsString( '>Send idea</button>', $html );
		$this->assertSame(
			[
				'pending' => 'Got it! We read every idea.',
				'publish' => 'Got it! Your idea is up.',
			],
			$this->context( $html )['messages']
		);

		$html = do_shortcode( '[' . Blocks::FORM_SHORTCODE . ' heading="" submit_label="<em>Go</em>"]' );
		$this->assertStringContainsString( '>Suggest a feature</h2>', $html, 'Empty means the default.' );
		$this->assertStringContainsString( '>&lt;em&gt;Go&lt;/em&gt;</button>', $html );

		$_GET[ SubmitForm::PARAM_NOTICE ] = 'pending';
		$this->assertStringContainsString( '<span data-wp-text="context.message">Got it! We read every idea.</span>', do_shortcode( '[' . Blocks::FORM_SHORTCODE . ' pending_message="Got it! We read every idea."]' ) );
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
	 * Returns the form's Interactivity API context, which the script reads.
	 *
	 * @param string $html Form HTML.
	 * @return array<string, mixed>
	 */
	private function context( string $html ): array {
		$this->assertSame( 1, preg_match( "/data-wp-context='([^']*)'/", $html, $match ) );

		return json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}
}
