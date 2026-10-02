<?php
/**
 * Descriptions that stay plain text.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\RequestPostType;
use WP_Embed;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps WordPress from embedding a web address in a request's description.
 *
 * A description that came through the form is plain text, and a visitor chose every word
 * of it. WordPress turns an address that stands alone on a line into a player or a
 * framed page. The server would fetch an address the visitor picked, and everyone who
 * opens the request would load content from that site. So in a request, an address
 * stays text: on its page, and in the editor, which would otherwise show a preview.
 *
 * A moderator who wants a video in a request wraps its address in the [embed] shortcode.
 * Visitors cannot: the square brackets they type are stored as text.
 *
 * Requests are edited in the classic editor. On a site that turns the block editor on
 * for them, a request written with blocks is left alone, so that its Embed blocks work.
 */
final class PlainDescription {

	/**
	 * Priority at which WordPress embeds addresses when it formats content.
	 */
	private const EMBED_PRIORITY = 8;

	/**
	 * Whether embedding is turned off right now.
	 *
	 * @var bool
	 */
	private static $paused = false;

	/**
	 * Adds the hooks around WordPress's embedding.
	 */
	public static function add_hooks(): void {
		add_filter( 'the_content', [ self::class, 'pause_embeds' ], self::EMBED_PRIORITY - 1 );
		add_filter( 'the_content', [ self::class, 'resume_embeds' ], self::EMBED_PRIORITY + 1 );
		add_filter( 'embed_cache_oembed_types', [ self::class, 'without_requests' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'keep_addresses_plain_in_the_editor' ] );
	}

	/**
	 * Turns embedding off while a request that is plain text is formatted.
	 *
	 * @param string $content Post content, before WordPress embeds addresses.
	 */
	public static function pause_embeds( string $content ): string {
		global $wp_embed;

		if ( ! self::$paused && $wp_embed instanceof WP_Embed && RequestPostType::NAME === get_post_type() && ! has_blocks( $content ) ) {
			self::$paused = remove_filter( 'the_content', [ $wp_embed, 'autoembed' ], self::EMBED_PRIORITY );
		}

		return $content;
	}

	/**
	 * Turns embedding back on for whatever is formatted next.
	 *
	 * @param string $content Post content, after WordPress would have embedded addresses.
	 */
	public static function resume_embeds( string $content ): string {
		global $wp_embed;

		if ( self::$paused ) {
			add_filter( 'the_content', [ $wp_embed, 'autoembed' ], self::EMBED_PRIORITY );
			self::$paused = false;
		}

		return $content;
	}

	/**
	 * Stops the classic editor from showing a preview in place of an address, on the screen that edits a request.
	 *
	 * The editor still shows a preview of the [embed] shortcode.
	 */
	public static function keep_addresses_plain_in_the_editor(): void {
		$screen = get_current_screen();

		if ( $screen && 'post' === $screen->base && RequestPostType::NAME === $screen->post_type ) {
			wp_add_inline_script( 'mce-view', 'window.wp && wp.mce && wp.mce.views && wp.mce.views.unregister( "embedURL" );' );
		}
	}

	/**
	 * Leaves requests out of the post types whose addresses WordPress fetches ahead, when
	 * someone opens the classic editor.
	 *
	 * @param string[] $post_types Post types.
	 * @return string[]
	 */
	public static function without_requests( array $post_types ): array {
		return array_diff( $post_types, [ RequestPostType::NAME ] );
	}
}
