<?php
/**
 * Official responses.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Comments;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\RequestPostType;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * A moderator's comment on a request can be marked as the team's official response.
 *
 * The mark is comment meta, so it goes when the comment goes. A request can have several
 * official responses over time; the newest approved one is pinned above the comments.
 */
final class OfficialResponse {

	/**
	 * Comment meta: set on comments that are official responses.
	 */
	public const META = '_mwtfrs_official';

	/**
	 * Whether a comment is marked as an official response.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function is_official( int $comment_id ): bool {
		return (bool) get_comment_meta( $comment_id, self::META, true );
	}

	/**
	 * Whether the current user may mark a comment as official, or remove the mark.
	 *
	 * The comment must be on a request, and the current user a moderator who can edit it.
	 * Only a comment written by a moderator can get the mark, so it always means "from
	 * the team". Any comment can lose it, even after its author left the team.
	 *
	 * @param WP_Comment $comment  Comment.
	 * @param bool       $official True to check marking, false to check removing the mark.
	 */
	public static function can_mark( WP_Comment $comment, bool $official ): bool {
		$allowed = RequestPostType::NAME === get_post_type( (int) $comment->comment_post_ID )
			&& current_user_can( Capabilities::MODERATE )
			&& current_user_can( 'edit_comment', (int) $comment->comment_ID );

		if ( ! $allowed || ! $official ) {
			return $allowed;
		}

		return $comment->user_id && user_can( (int) $comment->user_id, Capabilities::MODERATE );
	}

	/**
	 * Marks or unmarks a comment as an official response.
	 *
	 * @param int  $comment_id Comment ID.
	 * @param bool $official   Whether the comment is an official response.
	 * @return bool False when the comment does not exist or the current user may not change it.
	 */
	public static function mark( int $comment_id, bool $official ): bool {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment || ! self::can_mark( $comment, $official ) ) {
			return false;
		}

		if ( $official ) {
			update_comment_meta( $comment_id, self::META, 1 );
		} else {
			delete_comment_meta( $comment_id, self::META );
		}

		return true;
	}

	/**
	 * Returns a request's newest approved official response.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function latest( int $request_id ): ?WP_Comment {
		$comments = get_comments(
			[
				'post_id'  => $request_id,
				'status'   => 'approve',
				'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One request's comments.
				'number'   => 1,
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
			]
		);

		return $comments[0] ?? null;
	}
}
