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
 * get a link under each comment to mark it or to remove its mark.
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
	 * Adds the label and the class.
	 */
	public static function add_hooks(): void {
		add_filter( 'comment_class', [ self::class, 'add_class' ], 10, 3 );
		add_filter( 'comment_text', [ self::class, 'add_label' ], self::LABEL_PRIORITY, 2 );
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
	 * Puts "Official response" above the text of an official response, and on a request's
	 * page gives moderators a link below the text to change the mark.
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

		// Only on the request's page: the Comments screen has its own action, and the REST API shows text only.
		$link = is_singular( RequestPostType::NAME ) ? OfficialResponseLink::for_comment( $comment, get_comment_link( $comment ) ) : '';

		return '' === $link ? $text : $text . '<p class="mwtfrs-official-action">' . $link . '</p>';
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
}
