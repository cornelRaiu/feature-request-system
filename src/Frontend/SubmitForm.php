<?php
/**
 * The request submission form.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Security\RequestOrigin;
use FeatureRequestSystem\Submission\DataUseNote;
use FeatureRequestSystem\Submission\SimilarRequests;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\SubmissionService;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the form for suggesting a feature, and handles its plain form post.
 *
 * With JavaScript the form submits through the REST API and suggests similar requests
 * while the visitor types. Without it the form posts to admin-post.php and the visitor
 * comes back to the page with a notice.
 */
final class SubmitForm {

	/**
	 * Block name, also the Interactivity API store namespace.
	 */
	public const BLOCK = 'mwtfrs/submit';

	/**
	 * The admin-post action, also used as the nonce action for logged-in users.
	 */
	public const ACTION = 'mwtfrs_submit';

	/**
	 * URL parameter with the notice code after a plain form post.
	 */
	public const PARAM_NOTICE = 'fr-form';

	/**
	 * Notice codes for submission errors.
	 */
	private const ERROR_NOTICES = [
		SubmissionService::ERROR_BOARD_NOT_FOUND => 'missing',
		SubmissionService::ERROR_CLOSED          => 'closed',
		SubmissionService::ERROR_LOGIN_REQUIRED  => 'login',
		SubmissionService::ERROR_RATE_LIMITED    => 'limit',
		SpamGuard::ERROR_REJECTED                => 'rejected',
	];

	/**
	 * Validation problems a notice can name, as in "invalid-title".
	 */
	private const INVALID_REASONS = [ 'title', 'title-long', 'details-long', 'category', 'name-long', 'email' ];

	/**
	 * Adds the form handler for logged-in visitors and guests.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Renders the form. Called by the block's render.php; the shortcode renders the block.
	 *
	 * @param array<string, mixed> $attributes         Block attributes: board (term ID, 0 for the default board).
	 * @param string               $wrapper_attributes Attributes from get_block_wrapper_attributes().
	 */
	public static function render( array $attributes, string $wrapper_attributes ): string {
		$board = BoardTaxonomy::resolve( (int) ( $attributes['board'] ?? 0 ) );
		if ( ! $board ) {
			return '';
		}

		$rule      = BoardSettings::submitting( $board->term_id );
		$logged_in = is_user_logged_in();
		$page_url  = CurrentPage::url();
		$notice    = self::notice_from_request();

		if ( BoardSettings::NOBODY === $rule ) {
			$mode = 'closed';
		} elseif ( BoardSettings::LOGGED_IN === $rule && ! $logged_in ) {
			$mode = 'login';
		} else {
			$mode = 'form';
		}

		self::provide_state();

		return Template::render(
			'submit-form',
			[
				'namespace'          => self::BLOCK,
				'wrapper_attributes' => $wrapper_attributes,
				'anchor'             => self::anchor( $board->term_id ),
				'mode'               => $mode,
				'context'            => [
					'board'        => $board->term_id,
					'similar'      => [],
					'searchTicket' => 0,
					'announcement' => '',
					'busy'         => false,
					'message'      => $notice ? $notice['message'] : '',
					'isError'      => $notice ? $notice['error'] : false,
					'needsLogin'   => $notice ? $notice['login'] : false,
					'url'          => '',
				],
				'id_prefix'          => wp_unique_id( 'mwtfrs-submit-' ),
				'board_id'           => $board->term_id,
				'categories'         => self::categories( $board->term_id ),
				'guest_fields'       => ! $logged_in,
				'data_use'           => DataUseNote::html(),
				'privacy_url'        => get_privacy_policy_url(),
				'limits'             => [
					'title'       => SubmissionService::MAX_TITLE,
					'description' => SubmissionService::MAX_DESCRIPTION,
					'name'        => SubmissionService::MAX_NAME,
					'email'       => SubmissionService::MAX_EMAIL,
				],
				'form_url'           => admin_url( 'admin-post.php' ),
				'form_action'        => self::ACTION,
				'nonce'              => $logged_in ? wp_create_nonce( self::ACTION ) : '',
				'redirect_to'        => $page_url,
				'login_url'          => wp_login_url( $page_url ),
				'register_url'       => get_option( 'users_can_register' ) ? wp_registration_url() : '',
				'trap_field'         => SpamGuard::FIELD_TRAP,
				'token_field'        => SpamGuard::FIELD_TOKEN,
				'token'              => SpamGuard::token(),
			]
		);
	}

	/**
	 * Handles the plain form post and redirects back to the form.
	 */
	public static function handle(): void {
		$input   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() checks the nonce for logged-in users and the origin for guests.
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		wp_safe_redirect( self::process( $input, $origin, $referer ) );
		exit;
	}

	/**
	 * Submits the request and returns the URL to send the visitor back to.
	 *
	 * @param array<string, mixed> $input   Posted fields.
	 * @param string               $origin  Origin header.
	 * @param string               $referer Referer header.
	 */
	public static function process( array $input, string $origin, string $referer ): string {
		$board_id = absint( $input['board'] ?? 0 );
		$back     = wp_validate_redirect( esc_url_raw( (string) ( $input['redirect_to'] ?? '' ) ), home_url( '/' ) );
		$notice   = self::submit( $board_id, $input, $origin, $referer );

		return add_query_arg( self::PARAM_NOTICE, $notice, $back ) . '#' . self::anchor( $board_id );
	}

	/**
	 * Returns the notice from the current URL, if there is one.
	 *
	 * @return array{message: string, error: bool, login: bool}|null
	 */
	public static function notice_from_request(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a message to display.
		$code = isset( $_GET[ self::PARAM_NOTICE ] ) && is_string( $_GET[ self::PARAM_NOTICE ] ) ? sanitize_key( $_GET[ self::PARAM_NOTICE ] ) : '';

		$message = self::message( $code );
		if ( '' === $message ) {
			return null;
		}

		return [
			'message' => $message,
			'error'   => ! in_array( $code, [ 'pending', 'published' ], true ),
			'login'   => 'login' === $code,
		];
	}

	/**
	 * HTML id of the form for a board, the anchor to return to after a plain form post.
	 *
	 * @param int $board_id Board term ID.
	 */
	public static function anchor( int $board_id ): string {
		return 'mwtfrs-submit-' . $board_id;
	}

	/**
	 * Checks the post is genuine, then submits the request.
	 *
	 * @param int                  $board_id Board term ID.
	 * @param array<string, mixed> $input    Posted fields.
	 * @param string               $origin   Origin header.
	 * @param string               $referer  Referer header.
	 * @return string Notice code.
	 */
	private static function submit( int $board_id, array $input, string $origin, string $referer ): string {
		if ( is_user_logged_in() ) {
			if ( ! wp_verify_nonce( (string) ( $input['_wpnonce'] ?? '' ), self::ACTION ) ) {
				return 'expired';
			}
		} elseif ( ! RequestOrigin::is_same_site( $origin, $referer ) ) {
			return 'origin';
		}

		$result = SubmissionService::submit( $board_id, $input );
		if ( ! is_wp_error( $result ) ) {
			return 'publish' === $result['status'] ? 'published' : 'pending';
		}

		if ( SubmissionService::ERROR_INVALID === $result->get_error_code() ) {
			$data   = $result->get_error_data();
			$reason = is_array( $data ) && isset( $data['reason'] ) ? (string) $data['reason'] : 'title';

			return 'invalid-' . $reason;
		}

		return self::ERROR_NOTICES[ $result->get_error_code() ] ?? 'error';
	}

	/**
	 * Returns the message for a notice code, or '' for an unknown code.
	 *
	 * @param string $code Notice code.
	 */
	private static function message( string $code ): string {
		if ( 0 === strpos( $code, 'invalid-' ) ) {
			$reason = substr( $code, strlen( 'invalid-' ) );

			return in_array( $reason, self::INVALID_REASONS, true ) ? SubmissionService::invalid_message( $reason ) : '';
		}

		$messages = [
			'pending'   => __( 'Thanks! Your request will appear on the board once it is approved.', 'feature-request-system' ),
			'published' => __( 'Thanks! Your request is on the board.', 'feature-request-system' ),
			'login'     => __( 'Log in to suggest a feature.', 'feature-request-system' ),
			'closed'    => __( 'This board is not taking new requests.', 'feature-request-system' ),
			'limit'     => __( 'You have sent several requests in a short time. Try again later.', 'feature-request-system' ),
			'rejected'  => __( 'Your request could not be sent. Reload the page and try again.', 'feature-request-system' ),
			'expired'   => __( 'This page has expired. Reload it and try again.', 'feature-request-system' ),
			'origin'    => __( 'Your request could not be verified. Reload the page and try again.', 'feature-request-system' ),
			'missing'   => __( 'This board does not exist.', 'feature-request-system' ),
			'error'     => __( 'Your request could not be saved. Try again.', 'feature-request-system' ),
		];

		return $messages[ $code ] ?? '';
	}

	/**
	 * Categories for the dropdown, by ID, alphabetically: the ones the board is limited to, or all of them.
	 *
	 * @param int $board_id Board term ID.
	 * @return array<int, string>
	 */
	private static function categories( int $board_id ): array {
		$terms = get_terms(
			[
				'taxonomy'   => CategoryTaxonomy::NAME,
				'hide_empty' => false,
				'orderby'    => 'name',
				'include'    => BoardSettings::categories( $board_id ),
			]
		);

		return is_array( $terms ) ? wp_list_pluck( $terms, 'name', 'term_id' ) : [];
	}

	/**
	 * Shares the settings the form script needs. Holds nothing about the visitor except,
	 * for logged-in users, the REST nonce WordPress requires.
	 */
	private static function provide_state(): void {
		wp_interactivity_state(
			self::BLOCK,
			[
				'restUrl'         => rest_url( RestApi::NAMESPACE . '/' ),
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'minSearchLength' => SimilarRequests::MIN_LENGTH,
				'strings'         => [
					'error'        => __( 'Your request could not be sent. Check your connection and try again.', 'feature-request-system' ),
					/* translators: %d: number of similar requests found. */
					'similarFound' => __( 'Similar requests found: %d.', 'feature-request-system' ),
				],
			]
		);
	}
}
