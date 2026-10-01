<?php
/**
 * Official response action on the Comments screen.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Comments\OfficialResponse;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Mark as official response" to a moderator's comments on requests, next to Approve and Reply.
 */
final class CommentActions {

	/**
	 * The admin-post action, also the key of the row action and the start of its nonce action.
	 */
	public const ACTION = 'mwtfrs_mark_official';

	/**
	 * Adds the row action and its handler.
	 */
	public static function add_hooks(): void {
		add_filter( 'comment_row_actions', [ self::class, 'add_row_action' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Adds the action to comments the current user may mark, or remove the mark from.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Comment            $comment Comment in the row.
	 * @return array<string, string>
	 */
	public static function add_row_action( array $actions, WP_Comment $comment ): array {
		$comment_id = (int) $comment->comment_ID;
		$official   = OfficialResponse::is_official( $comment_id );

		if ( ! OfficialResponse::can_mark( $comment, ! $official ) ) {
			return $actions;
		}

		$actions[ self::ACTION ] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::url( $comment_id, ! $official ) ),
			$official
				? esc_html__( 'Remove official mark', 'feature-request-system' )
				: esc_html__( 'Mark as official response', 'feature-request-system' )
		);

		return $actions;
	}

	/**
	 * Marks or unmarks the comment, then returns to the screen the link was on.
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

	/**
	 * URL that marks or unmarks a comment.
	 *
	 * @param int  $comment_id Comment ID.
	 * @param bool $official   Whether the link marks the comment as official.
	 */
	public static function url( int $comment_id, bool $official ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => self::ACTION,
					'comment'  => $comment_id,
					'official' => $official ? '1' : '0',
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $comment_id
		);
	}
}
