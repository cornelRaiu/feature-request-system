<?php
/**
 * Tests for descriptions that stay plain text.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Frontend\PlainDescription;
use WP_Embed;

/**
 * WordPress embeds an address that stands alone on a line. In a request that is plain
 * text, as the form stores it, it must not.
 */
final class PlainDescriptionTest extends TestCase {

	/**
	 * A description as a visitor could send it: an address of a video site and one of a
	 * video file, each alone on its line.
	 */
	private const TEXT = "Please add this, as shown here:\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nAnd in this clip:\nhttps://files.example.net/clip.mp4\nThank you.";

	/**
	 * Addresses the server was asked to fetch.
	 *
	 * @var string[]
	 */
	private $fetched = [];

	public function set_up(): void {
		parent::set_up();

		// Stands in for the video site: it answers every request with a player.
		add_filter(
			'pre_http_request',
			function ( $response, array $args, string $url ) {
				$this->fetched[] = $url;

				return [
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => (string) wp_json_encode(
						[
							'version' => '1.0',
							'type'    => 'video',
							'html'    => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="500" height="281"></iframe>',
						]
					),
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
		wp_reset_postdata();
		wp_scripts()->add_data( 'mce-view', 'after', [] );
		parent::tear_down();
	}

	public function test_wordpress_would_embed_these_addresses_in_a_post(): void {
		$html = $this->formatted( self::factory()->post->create( [ 'post_content' => self::TEXT ] ) );

		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringContainsString( '<video', $html );
		$this->assertNotSame( [], $this->fetched );
	}

	public function test_addresses_in_a_request_that_is_plain_text_stay_text(): void {
		$html = $this->formatted( $this->create_request( [ 'post_content' => self::TEXT ] ) );

		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<video', $html );
		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $html );
		$this->assertStringContainsString( 'https://files.example.net/clip.mp4', $html );
		$this->assertStringContainsString( 'Thank you.', $html );
		$this->assertSame( [], $this->fetched, 'The server fetched nothing.' );
	}

	public function test_what_is_formatted_after_a_request_is_embedded_again(): void {
		$this->formatted( $this->create_request( [ 'post_content' => self::TEXT ] ) );

		$this->assertStringContainsString( '<iframe', $this->formatted( self::factory()->post->create( [ 'post_content' => self::TEXT ] ) ) );
	}

	public function test_a_moderator_can_still_embed_with_the_shortcode(): void {
		$request = $this->create_request( [ 'post_content' => "Here is how it could look:\n\n[embed]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/embed]" ] );

		$this->assertStringContainsString( '<iframe', $this->formatted( $request ) );
	}

	public function test_an_embed_block_that_a_moderator_added_to_a_request_works(): void {
		$url     = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$request = $this->create_request(
			[
				'post_content' => '<!-- wp:paragraph --><p>Here is how it could look.</p><!-- /wp:paragraph -->' . "\n\n"
					. '<!-- wp:embed {"url":"' . $url . '","type":"video","providerNameSlug":"youtube"} -->' . "\n"
					. '<figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">' . "\n" . $url . "\n" . '</div></figure>' . "\n"
					. '<!-- /wp:embed -->',
			]
		);

		$this->assertStringContainsString( '<iframe', $this->formatted( $request ) );
	}

	public function test_a_site_that_turned_embedding_off_keeps_it_off(): void {
		global $wp_embed;

		$this->assertInstanceOf( WP_Embed::class, $wp_embed );
		remove_filter( 'the_content', [ $wp_embed, 'autoembed' ], 8 );

		$this->formatted( $this->create_request( [ 'post_content' => self::TEXT ] ) );
		$html = $this->formatted( self::factory()->post->create( [ 'post_content' => self::TEXT ] ) );
		add_filter( 'the_content', [ $wp_embed, 'autoembed' ], 8 );

		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_the_classic_editor_does_not_fetch_the_addresses_of_a_request_ahead(): void {
		global $wp_embed;

		$wp_embed->cache_oembed( $this->create_request( [ 'post_content' => self::TEXT ] ) );
		$this->assertSame( [], $this->fetched );

		$wp_embed->cache_oembed( self::factory()->post->create( [ 'post_content' => self::TEXT ] ) );
		$this->assertNotSame( [], $this->fetched, 'It does for a post.' );
	}

	public function test_the_editor_of_a_request_shows_addresses_as_text_and_other_editors_are_left_alone(): void {
		$script = 'wp.mce.views.unregister( "embedURL" )';

		set_current_screen( 'post' );
		PlainDescription::keep_addresses_plain_in_the_editor();
		set_current_screen( 'edit-' . RequestPostType::NAME );
		PlainDescription::keep_addresses_plain_in_the_editor();
		$this->assertStringNotContainsString( $script, implode( '', (array) wp_scripts()->get_data( 'mce-view', 'after' ) ), 'Not for posts, and not on the list of requests.' );

		set_current_screen( RequestPostType::NAME );
		PlainDescription::keep_addresses_plain_in_the_editor();
		set_current_screen( 'front' );

		$this->assertStringContainsString( $script, implode( '', (array) wp_scripts()->get_data( 'mce-view', 'after' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', [ PlainDescription::class, 'keep_addresses_plain_in_the_editor' ] ) );
	}

	/**
	 * Returns a post's content as its page shows it.
	 *
	 * @param int $post_id Post ID.
	 */
	private function formatted( int $post_id ): string {
		global $post;

		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for the loop of a page.
		setup_postdata( $post );
		$this->assertSame( RequestPostType::NAME === $post->post_type, RequestPostType::NAME === get_post_type() );

		return (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
	}
}
