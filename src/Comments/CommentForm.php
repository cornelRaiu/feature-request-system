<?php
/**
 * Official response option on the comment form.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Comments;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Lets moderators post a comment on a request as the official response.
 *
 * The checkbox comes with a nonce. WordPress accepts comments from logged-in users without
 * one, and without it another site could make a moderator post an "official" comment.
 */
final class CommentForm {

	/**
	 * Name of the checkbox.
	 */
	public const FIELD = 'mwtfrs_official';

	/**
	 * Name of the nonce field.
	 */
	public const NONCE_FIELD = 'mwtfrs_official_nonce';

	/**
	 * Nonce action.
	 */
	public const NONCE_ACTION = 'mwtfrs_official_response';

	/**
	 * Adds the checkbox to the form and saves it with the comment.
	 */
	public static function add_hooks(): void {
		add_filter( 'comment_form_submit_field', [ self::class, 'add_checkbox' ] );
		add_action( 'comment_post', [ self::class, 'save' ] );
	}

	/**
	 * Puts the checkbox above the submit button, for moderators commenting on a request.
	 *
	 * @param string $submit_field HTML of the submit button and the form's hidden fields.
	 */
	public static function add_checkbox( string $submit_field ): string {
		if ( RequestPostType::NAME !== get_post_type() || ! current_user_can( Capabilities::MODERATE ) ) {
			return $submit_field;
		}

		$checkbox = sprintf(
			'<p class="comment-form-official-response"><label><input type="checkbox" name="%1$s" value="1"> %2$s</label>%3$s</p>',
			esc_attr( self::FIELD ),
			esc_html__( 'Post as the official response', 'feature-request-system' ),
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false )
		);

		return $checkbox . $submit_field;
	}

	/**
	 * Marks a new comment as official when the checkbox was ticked.
	 *
	 * @param int $comment_id ID of the comment just saved.
	 */
	public static function save( int $comment_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( empty( $_POST[ self::FIELD ] ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		OfficialResponse::mark( $comment_id, true );
	}
}
