<?php
/**
 * Official responses in the comment thread.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Comments;

use FeatureRequestSystem\Content\RequestPostType;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Labels official responses wherever WordPress shows comments: the theme's comment list,
 * the Comments block and the admin Comments screen. On a request's page, moderators also
 * get a link next to each team comment's Reply link, to mark it or to remove its mark.
 */
final class CommentThread {

	/**
	 * CSS class of an official response in the comment list, for themes to style.
	 */
	private const CSS_CLASS = 'mwtfrs-official';

	/**
	 * Priority of the label filter: after WordPress has turned the text into paragraphs.
	 */
	private const LABEL_PRIORITY = 40;

	/**
	 * Adds the label, the class and the moderators' link.
	 */
	public static function add_hooks(): void {
		add_filter( 'comment_class', [ self::class, 'add_class' ], 10, 3 );
		add_filter( 'comment_text', [ self::class, 'add_label' ], self::LABEL_PRIORITY, 2 );
		add_filter( 'comment_reply_link', [ self::class, 'add_to_reply_line' ], 10, 3 );
	}

	/**
	 * Gives official responses a CSS class.
	 *
	 * @param string[]   $classes    Classes of the comment.
	 * @param string[]   $css_class  Classes added by the caller.
	 * @param int|string $comment_id Comment ID.
	 * @return string[]
	 */
	public static function add_class( array $classes, $css_class, $comment_id ): array {
		if ( OfficialResponse::is_official( (int) $comment_id ) ) {
			$classes[] = self::CSS_CLASS;
		}

		return $classes;
	}

	/**
	 * Puts "Official response" above the text of an official response.
	 *
	 * Moderators get their link on the comment's Reply line. A comment without one gets
	 * the link here instead, below its text.
	 *
	 * @param string          $text    Comment text, already formatted.
	 * @param WP_Comment|null $comment Comment. Missing when other code applies the filter to plain text.
	 */
	public static function add_label( string $text, $comment = null ): string {
		if ( ! $comment instanceof WP_Comment ) {
			return $text;
		}

		if ( OfficialResponse::is_official( (int) $comment->comment_ID ) ) {
			$text = '<p class="mwtfrs-official-label"><strong>' . esc_html__( 'Official response', 'feature-request-system' ) . '</strong></p>' . $text;
		}

		$link = self::link_for( $comment );

		return '' === $link || self::has_reply_line( $comment )
			? $text
			: $text . '<p class="mwtfrs-official-action">' . $link . '</p>';
	}

	/**
	 * Puts the moderators' link next to a comment's Reply link, on the same line.
	 *
	 * Classic themes and the Comments block both build the Reply link through this filter.
	 *
	 * @param mixed $html    HTML of the Reply link, with what the theme puts around it.
	 * @param mixed $args    Arguments of the Reply link. "after" holds the markup that closes it.
	 * @param mixed $comment Comment.
	 * @return mixed
	 */
	public static function add_to_reply_line( $html, $args = [], $comment = null ) {
		if ( ! is_string( $html ) || ! $comment instanceof WP_Comment || ! self::has_reply_line( $comment ) ) {
			return $html;
		}

		$link = self::link_for( $comment );
		if ( '' === $link ) {
			return $html;
		}

		$link  = ' <span class="mwtfrs-official-toggle">' . $link . '</span>';
		$after = is_array( $args ) && isset( $args['after'] ) ? (string) $args['after'] : '';

		// Inside what the theme wraps around the Reply link, so the two stay on one line.
		return '' !== $after && substr( $html, -strlen( $after ) ) === $after
			? substr( $html, 0, -strlen( $after ) ) . $link . $after
			: $html . $link;
	}

	/**
	 * Returns a comment's formatted text without the label and the link, for the pinned
	 * response, which has its own.
	 *
	 * @param WP_Comment $comment Comment.
	 */
	public static function text_without_label( WP_Comment $comment ): string {
		remove_filter( 'comment_text', [ self::class, 'add_label' ], self::LABEL_PRIORITY );
		$text = (string) apply_filters( 'comment_text', get_comment_text( $comment ), $comment, [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, applied so the text is formatted like in the thread.
		add_filter( 'comment_text', [ self::class, 'add_label' ], self::LABEL_PRIORITY, 2 );

		return $text;
	}

	/**
	 * Returns the link that changes a comment's mark, for moderators on a request's page.
	 * Elsewhere it returns '': the Comments screen has its own action, and the REST API shows text only.
	 *
	 * @param WP_Comment $comment Comment.
	 */
	private static function link_for( WP_Comment $comment ): string {
		return is_singular( RequestPostType::NAME ) ? OfficialResponseLink::for_comment( $comment, get_comment_link( $comment ) ) : '';
	}

	/**
	 * Whether WordPress shows a Reply link for a comment: threaded comments are on, the
	 * request still takes comments, and the comment is not at the deepest level.
	 *
	 * @param WP_Comment $comment Comment.
	 */
	private static function has_reply_line( WP_Comment $comment ): bool {
		if ( ! get_option( 'thread_comments' ) || ! comments_open( (int) $comment->comment_post_ID ) ) {
			return false;
		}

		$depth  = 1;
		$parent = (int) $comment->comment_parent;
		while ( $parent ) {
			$ancestor = get_comment( $parent );
			$parent   = $ancestor ? (int) $ancestor->comment_parent : 0;
			++$depth;
		}

		return $depth < (int) get_option( 'thread_comments_depth' );
	}
}
