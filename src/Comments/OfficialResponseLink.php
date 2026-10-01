<?php
/**
 * Links that mark a comment as an official response, or remove the mark.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Comments;

use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the "Mark as official response" and "Remove official mark" links that moderators
 * see on a request's page and on the Comments screen, and handles a click on one.
 */
final class OfficialResponseLink {

	/**
	 * The admin-post action, also the start of the link's nonce action.
	 */
	public const ACTION = 'mwtfrs_mark_official';

	/**
	 * Adds the handler for the links.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Returns the link that changes a comment's mark, or '' when the current user may not change it.
	 *
	 * @param WP_Comment $comment Comment.
	 * @param string     $back    URL to return to. Defaults to the page the link is on.
	 */
	public static function for_comment( WP_Comment $comment, string $back = '' ): string {
		$official = OfficialResponse::is_official( (int) $comment->comment_ID );
		if ( ! OfficialResponse::can_mark( $comment, ! $official ) ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::url( (int) $comment->comment_ID, ! $official, $back ) ),
			$official
				? esc_html__( 'Remove official mark', 'feature-request-system' )
				: esc_html__( 'Mark as official response', 'feature-request-system' )
		);
	}

	/**
	 * URL that marks or unmarks a comment.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param bool   $official   Whether the link marks the comment as official.
	 * @param string $back       URL to return to. Defaults to the page the link is on.
	 */
	public static function url( int $comment_id, bool $official, string $back = '' ): string {
		$args = [
			'action'   => self::ACTION,
			'comment'  => $comment_id,
			'official' => $official ? '1' : '0',
		];

		if ( '' !== $back ) {
			// WordPress reads this before the Referer header, which browsers do not always send.
			$args['_wp_http_referer'] = rawurlencode( $back );
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $comment_id );
	}

	/**
	 * Marks or unmarks the comment, then returns to where the link was.
	 */
	public static function handle(): void {
		$comment_id = isset( $_GET['comment'] ) ? absint( $_GET['comment'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $comment_id );

		if ( ! OfficialResponse::mark( $comment_id, isset( $_GET['official'] ) && '1' === $_GET['official'] ) ) {
			wp_die( esc_html__( 'You are not allowed to change this comment.', 'feature-request-system' ), '', [ 'response' => 403 ] );
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'edit-comments.php' ) );
		exit;
	}
}
