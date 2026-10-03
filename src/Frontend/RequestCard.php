<?php
/**
 * One feature request as a card.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Feature Request Card block: one chosen request with its vote button, summary
 * and status, in a post or a page that talks about it.
 *
 * Like the board, its HTML is the same for every visitor.
 */
final class RequestCard {

	/**
	 * Block name.
	 */
	public const BLOCK = 'mwtfrs/request-card';

	/**
	 * Renders a card, or nothing for a request that visitors cannot see. Called by the
	 * block's render.php; the shortcode renders the block.
	 *
	 * @param array<string, mixed> $attributes         Block attributes: requestId.
	 * @param string               $wrapper_attributes Attributes from get_block_wrapper_attributes().
	 */
	public static function render( array $attributes, string $wrapper_attributes ): string {
		$post = get_post( (int) ( $attributes['requestId'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || ! self::is_public( $post ) ) {
			return '';
		}

		$board    = RequestTerms::term_of( $post->ID, BoardTaxonomy::NAME );
		$page_url = CurrentPage::url();
		$item     = RequestItem::prepare( $post, BoardSettings::voting( $board ? $board->term_id : 0 ), RequestNotice::from_request(), wp_unique_id( 'mwtfrs-card-' ) );

		VoteStore::provide();

		return Template::render(
			'request-card',
			[
				'namespace'          => VoteStore::NAME,
				'wrapper_attributes' => $wrapper_attributes,
				'context'            => VoteStore::context( [ $item ] ),
				'item'               => $item,
				// Not a heading: a card sits in the middle of a text, under a heading of any level.
				'title_tag'          => 'p',
				'compact'            => false,
				'vote'               => VoteForm::template_args( $page_url ),
				'login_url'          => wp_login_url( $page_url ),
			]
		);
	}

	/**
	 * Whether a post is a request the board would show: published, and not behind a password.
	 *
	 * @param WP_Post $post Post chosen in the block.
	 */
	private static function is_public( WP_Post $post ): bool {
		return RequestPostType::NAME === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password;
	}
}
