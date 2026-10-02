<?php
/**
 * Submitting new requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Submission;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Security\RateLimiter;
use FeatureRequestSystem\Voting\VoterIdentity;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * The rules for submitting a request. The REST API and the plain form both go through here.
 *
 * New requests are held for approval unless the board turns moderation off or the
 * submitter can publish requests themselves. Submitters are not stored as post authors:
 * a logged-in submitter's ID, and a visitor's name and email, go into private post meta.
 */
final class SubmissionService {

	/**
	 * The board does not exist.
	 */
	public const ERROR_BOARD_NOT_FOUND = 'mwtfrs_board_not_found';

	/**
	 * The board is not taking new requests.
	 */
	public const ERROR_CLOSED = 'mwtfrs_submissions_closed';

	/**
	 * The board only takes requests from logged-in users.
	 */
	public const ERROR_LOGIN_REQUIRED = 'mwtfrs_submit_login_required';

	/**
	 * A field is missing or invalid. The error data names the problem in "reason".
	 */
	public const ERROR_INVALID = 'mwtfrs_submission_invalid';

	/**
	 * The visitor submitted too often. The error data holds retry_after in seconds.
	 */
	public const ERROR_RATE_LIMITED = 'mwtfrs_submit_rate_limited';

	/**
	 * The request could not be saved.
	 */
	public const ERROR_FAILED = 'mwtfrs_submission_failed';

	/**
	 * Action fired after a request is submitted. Receives the request ID.
	 */
	public const ACTION_SUBMITTED = 'mwtfrs_request_submitted';

	/**
	 * Longest title, in characters.
	 */
	public const MAX_TITLE = 150;

	/**
	 * Longest description, in characters.
	 */
	public const MAX_DESCRIPTION = 5000;

	/**
	 * Longest name a visitor may give, in characters.
	 */
	public const MAX_NAME = 60;

	/**
	 * Longest email address a visitor may give, in characters. No working address is longer.
	 */
	public const MAX_EMAIL = 254;

	/**
	 * Checks whether the current visitor may submit to a board.
	 *
	 * @param int $board_id Board term ID.
	 * @return true|WP_Error
	 */
	public static function check( int $board_id ) {
		if ( ! get_term( $board_id, BoardTaxonomy::NAME ) instanceof WP_Term ) {
			return new WP_Error( self::ERROR_BOARD_NOT_FOUND, __( 'This board does not exist.', 'feature-request-system' ), [ 'status' => 404 ] );
		}

		$rule = BoardSettings::submitting( $board_id );
		if ( BoardSettings::NOBODY === $rule ) {
			return new WP_Error( self::ERROR_CLOSED, __( 'This board is not taking new requests.', 'feature-request-system' ), [ 'status' => 403 ] );
		}
		if ( BoardSettings::LOGGED_IN === $rule && ! is_user_logged_in() ) {
			return new WP_Error( self::ERROR_LOGIN_REQUIRED, __( 'Log in to suggest a feature.', 'feature-request-system' ), [ 'status' => 401 ] );
		}

		return true;
	}

	/**
	 * Submits a request to a board.
	 *
	 * @param int                  $board_id Board term ID.
	 * @param array<string, mixed> $fields   Submitted fields: title, description, category, name and email,
	 *                                       plus the SpamGuard fields.
	 * @return array{id: int, status: string, url: string}|WP_Error The new request, or why it was refused.
	 */
	public static function submit( int $board_id, array $fields ) {
		$allowed = self::check( $board_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$guard = SpamGuard::check( $fields );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$wait = RateLimiter::attempt( 'submit', VoterIdentity::key() );
		if ( $wait ) {
			return new WP_Error(
				self::ERROR_RATE_LIMITED,
				__( 'You have sent several requests in a short time. Try again later.', 'feature-request-system' ),
				[
					'status'      => 429,
					'retry_after' => $wait,
				]
			);
		}

		$submission = self::validate( $fields, $board_id );
		if ( is_wp_error( $submission ) ) {
			return $submission;
		}

		$flagged = SpamGuard::is_spam( $submission );
		$status  = ! $flagged && ( ! BoardSettings::moderated( $board_id ) || current_user_can( 'publish_' . RequestPostType::CAPABILITY_TYPE ) )
			? 'publish'
			: 'pending';

		// wp_insert_post() expects slashed data, as from a form post.
		$request_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => RequestPostType::NAME,
					'post_status'  => $status,
					'post_title'   => $submission['title'],
					'post_content' => self::without_shortcodes( $submission['description'] ),
					'post_author'  => 0,
				]
			),
			true
		);
		if ( is_wp_error( $request_id ) ) {
			return new WP_Error( self::ERROR_FAILED, __( 'Your request could not be saved. Try again.', 'feature-request-system' ), [ 'status' => 500 ] );
		}

		wp_set_object_terms( $request_id, $board_id, BoardTaxonomy::NAME );
		if ( $submission['category'] ) {
			wp_set_object_terms( $request_id, $submission['category'], CategoryTaxonomy::NAME );
		}
		self::record_submitter( $request_id, $submission );
		if ( $flagged ) {
			update_post_meta( $request_id, RequestPostType::META_FLAGGED_AS_SPAM, 1 );
		}

		do_action( self::ACTION_SUBMITTED, $request_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		return [
			'id'     => $request_id,
			'status' => $status,
			'url'    => 'publish' === $status ? (string) get_permalink( $request_id ) : '',
		];
	}

	/**
	 * Cleans the submitted fields and checks them.
	 *
	 * @param array<string, mixed> $fields   Submitted fields.
	 * @param int                  $board_id Board the request is for.
	 * @return array{title: string, description: string, category: int, name: string, email: string}|WP_Error
	 */
	private static function validate( array $fields, int $board_id ) {
		$title       = sanitize_text_field( self::text( $fields, 'title' ) );
		$description = sanitize_textarea_field( self::text( $fields, 'description' ) );
		$category    = absint( self::text( $fields, 'category' ) );
		$name        = is_user_logged_in() ? '' : sanitize_text_field( self::text( $fields, 'name' ) );
		$raw_email   = is_user_logged_in() ? '' : trim( self::text( $fields, 'email' ) );
		$email       = sanitize_email( $raw_email );

		$problems = [
			'title'        => '' === $title,
			'title-long'   => mb_strlen( $title ) > self::MAX_TITLE,
			'details-long' => mb_strlen( $description ) > self::MAX_DESCRIPTION,
			'category'     => $category && ! self::is_category_of( $category, $board_id ),
			'name-long'    => mb_strlen( $name ) > self::MAX_NAME,
			'email'        => '' !== $raw_email && ( strlen( $raw_email ) > self::MAX_EMAIL || ! is_email( $email ) ),
		];

		foreach ( $problems as $reason => $failed ) {
			if ( $failed ) {
				return new WP_Error(
					self::ERROR_INVALID,
					self::invalid_message( $reason ),
					[
						'status' => 400,
						'reason' => $reason,
					]
				);
			}
		}

		return [
			'title'       => $title,
			'description' => $description,
			'category'    => $category,
			'name'        => $name,
			'email'       => $email,
		];
	}

	/**
	 * Whether a category exists and the board's requests can have it.
	 *
	 * @param int $category_id Category term ID.
	 * @param int $board_id    Board term ID.
	 */
	private static function is_category_of( int $category_id, int $board_id ): bool {
		$allowed = BoardSettings::categories( $board_id );

		return term_exists( $category_id, CategoryTaxonomy::NAME ) && ( ! $allowed || in_array( $category_id, $allowed, true ) );
	}

	/**
	 * Explains what to fix for a validation problem.
	 *
	 * @param string $reason Problem code from validate().
	 */
	public static function invalid_message( string $reason ): string {
		$messages = [
			/* translators: %d: maximum number of characters. */
			'title-long'   => sprintf( __( 'Keep the title under %d characters.', 'feature-request-system' ), self::MAX_TITLE ),
			/* translators: %s: maximum number of characters. */
			'details-long' => sprintf( __( 'Keep the details under %s characters.', 'feature-request-system' ), number_format_i18n( self::MAX_DESCRIPTION ) ),
			'category'     => __( 'Choose a category from the list.', 'feature-request-system' ),
			/* translators: %d: maximum number of characters. */
			'name-long'    => sprintf( __( 'Keep your name under %d characters.', 'feature-request-system' ), self::MAX_NAME ),
			'email'        => __( 'Enter a valid email address, or leave it empty.', 'feature-request-system' ),
		];

		return $messages[ $reason ] ?? __( 'Add a title for your request.', 'feature-request-system' );
	}

	/**
	 * Stores who submitted the request, in post meta that is never shown publicly.
	 *
	 * @param int                                                                                   $request_id Request ID.
	 * @param array{title: string, description: string, category: int, name: string, email: string} $submission Cleaned submission.
	 */
	private static function record_submitter( int $request_id, array $submission ): void {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_post_meta( $request_id, RequestPostType::META_SUBMITTER_ID, $user_id );
			return;
		}

		if ( '' !== $submission['name'] ) {
			update_post_meta( $request_id, RequestPostType::META_SUBMITTER_NAME, wp_slash( $submission['name'] ) );
		}
		if ( '' !== $submission['email'] ) {
			update_post_meta( $request_id, RequestPostType::META_SUBMITTER_EMAIL, wp_slash( $submission['email'] ) );
		}
	}

	/**
	 * Escapes square brackets, so a shortcode in a visitor's text shows as typed instead of running
	 * on the request's page. The entities display as brackets.
	 *
	 * @param string $text Plain text.
	 */
	private static function without_shortcodes( string $text ): string {
		return str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], $text );
	}

	/**
	 * Returns a field as a string, or '' when it is missing or not text.
	 *
	 * @param array<string, mixed> $fields Submitted fields.
	 * @param string               $name   Field name.
	 */
	private static function text( array $fields, string $name ): string {
		return isset( $fields[ $name ] ) && is_scalar( $fields[ $name ] ) ? (string) $fields[ $name ] : '';
	}
}
