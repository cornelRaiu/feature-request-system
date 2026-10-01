<?php
/**
 * Official response action on the Comments screen.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Comments\OfficialResponseLink;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Mark as official response" or "Remove official mark" to comments on requests,
 * next to Approve and Reply.
 */
final class CommentActions {

	/**
	 * Adds the row action.
	 */
	public static function add_hooks(): void {
		add_filter( 'comment_row_actions', [ self::class, 'add_row_action' ], 10, 2 );
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
}
