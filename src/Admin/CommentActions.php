<?php
/**
 * Official response controls on the admin's comment screens.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Comments\CommentForm;
use FeatureRequestSystem\Comments\OfficialResponse;
use FeatureRequestSystem\Comments\OfficialResponseLink;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * On the Comments screen, adds "Mark as official response" or "Remove official mark" to a
 * comment's actions, next to Approve and Reply. On a comment's edit screen, adds an
 * "Official response" checkbox to the Save box.
 */
final class CommentActions {

	/**
	 * Adds the row action and the checkbox.
	 */
	public static function add_hooks(): void {
		add_filter( 'comment_row_actions', [ self::class, 'add_row_action' ], 10, 2 );
		add_filter( 'edit_comment_misc_actions', [ self::class, 'add_checkbox' ], 10, 2 );
		add_action( 'edit_comment', [ self::class, 'save_checkbox' ] );
	}

	/**
	 * Adds the action to comments whose mark the current user may change.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Comment            $comment Comment in the row.
	 * @return array<string, string>
	 */
	public static function add_row_action( array $actions, WP_Comment $comment ): array {
		$link = OfficialResponseLink::for_comment( $comment );
		if ( '' !== $link ) {
			$actions[ OfficialResponseLink::ACTION ] = $link;
		}

		return $actions;
	}

	/**
	 * Adds the checkbox to the Save box of a comment whose mark the current user may change.
	 *
	 * @param string     $html    HTML of the extra items in the Save box.
	 * @param WP_Comment $comment Comment being edited.
	 */
	public static function add_checkbox( string $html, WP_Comment $comment ): string {
		$official = OfficialResponse::is_official( (int) $comment->comment_ID );
		if ( ! OfficialResponse::can_mark( $comment, ! $official ) ) {
			return $html;
		}

		// The hidden field sends 0 when the box is unticked, which tells an unticked box from a form without it.
		return $html . sprintf(
			'<div class="misc-pub-section"><input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label></div>',
			esc_attr( CommentForm::FIELD ),
			checked( $official, true, false ),
			esc_html__( 'Official response', 'feature-request-system' )
		);
	}

	/**
	 * Saves the checkbox when the comment's edit screen is saved.
	 *
	 * Comments are also updated by Quick Edit, the REST API and other code. Those send no
	 * checkbox, and must leave the mark as it is.
	 *
	 * @param int $comment_id ID of the comment just updated.
	 */
	public static function save_checkbox( int $comment_id ): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		// The edit screen's own nonce: this hook cannot tell whether WordPress already checked it.
		if ( ! isset( $_POST[ CommentForm::FIELD ] ) || ! wp_verify_nonce( $nonce, 'update-comment_' . $comment_id ) ) {
			return;
		}

		OfficialResponse::mark( $comment_id, '1' === $_POST[ CommentForm::FIELD ] );
	}
}
