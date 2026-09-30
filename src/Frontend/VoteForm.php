<?php
/**
 * Voting without JavaScript.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Security\RequestOrigin;
use FeatureRequestSystem\Voting\VoteRepository;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the vote button's plain form post, used when JavaScript is off or has not loaded.
 *
 * The board's HTML is the same for every visitor so it can be cached, which means the
 * form cannot know whether the visitor already voted. Submitting it toggles the vote,
 * then sends the visitor back to the board with a notice saying what happened.
 */
final class VoteForm {

	/**
	 * The admin-post action, also used as the nonce action for logged-in users.
	 */
	public const ACTION = 'mwtfrs_vote';

	/**
	 * URL parameter with the notice code after a vote.
	 */
	public const PARAM_NOTICE = 'fr-notice';

	/**
	 * URL parameter with the request the notice is about.
	 */
	public const PARAM_REQUEST = 'fr-request';

	/**
	 * Notice codes for the voting errors.
	 */
	private const ERROR_NOTICES = [
		VoteService::ERROR_NOT_FOUND      => 'missing',
		VoteService::ERROR_CLOSED         => 'closed',
		VoteService::ERROR_DISABLED       => 'disabled',
		VoteService::ERROR_LOGIN_REQUIRED => 'login',
		VoteService::ERROR_RATE_LIMITED   => 'limit',
	];

	/**
	 * Adds the form handler for logged-in visitors and guests.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Handles the form post and redirects back to the board.
	 */
	public static function handle(): void {
		$input   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() checks the nonce for logged-in users and the origin for guests.
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		wp_safe_redirect( self::process( $input, $origin, $referer ) );
		exit;
	}

	/**
	 * Toggles the visitor's vote and returns the URL to send them back to.
	 *
	 * @param array<string, mixed> $input   Posted fields: request_id, redirect_to and, for logged-in users, _wpnonce.
	 * @param string               $origin  Origin header.
	 * @param string               $referer Referer header.
	 */
	public static function process( array $input, string $origin, string $referer ): string {
		$request_id = absint( $input['request_id'] ?? 0 );
		$back       = wp_validate_redirect( esc_url_raw( (string) ( $input['redirect_to'] ?? '' ) ), home_url( '/' ) );
		$notice     = self::toggle( $request_id, (string) ( $input['_wpnonce'] ?? '' ), $origin, $referer );

		return add_query_arg(
			[
				self::PARAM_NOTICE  => $notice,
				self::PARAM_REQUEST => $request_id,
			],
			$back
		) . '#' . Board::item_id( $request_id );
	}

	/**
	 * Returns the notice from the current URL, if it is about a request on this page.
	 *
	 * @return array{request: int, message: string, login: bool}|null
	 */
	public static function notice_from_request(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only picks a message to display.
		$code    = isset( $_GET[ self::PARAM_NOTICE ] ) && is_string( $_GET[ self::PARAM_NOTICE ] ) ? sanitize_key( $_GET[ self::PARAM_NOTICE ] ) : '';
		$request = isset( $_GET[ self::PARAM_REQUEST ] ) ? absint( $_GET[ self::PARAM_REQUEST ] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = self::message( $code );
		if ( '' === $message || ! $request ) {
			return null;
		}

		return [
			'request' => $request,
			'message' => $message,
			'login'   => 'login' === $code,
		];
	}

	/**
	 * Checks the request is genuine, then casts or retracts the vote.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $nonce      Nonce from the form, sent to logged-in users only.
	 * @param string $origin     Origin header.
	 * @param string $referer    Referer header.
	 * @return string Notice code.
	 */
	private static function toggle( int $request_id, string $nonce, string $origin, string $referer ): string {
		if ( is_user_logged_in() ) {
			if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
				return 'expired';
			}
		} elseif ( ! RequestOrigin::is_same_site( $origin, $referer ) ) {
			return 'origin';
		}

		$voter  = VoterIdentity::key();
		$voted  = '' !== $voter && in_array( $request_id, VoteRepository::voted_among( $voter, [ $request_id ] ), true );
		$result = $voted ? VoteService::retract( $request_id ) : VoteService::cast( $request_id );

		if ( is_wp_error( $result ) ) {
			return self::ERROR_NOTICES[ $result->get_error_code() ] ?? 'error';
		}

		return $result['voted'] ? 'added' : 'removed';
	}

	/**
	 * Returns the message for a notice code, or '' for an unknown code.
	 *
	 * @param string $code Notice code.
	 */
	private static function message( string $code ): string {
		$messages = [
			'added'    => __( 'Your vote was added.', 'feature-request-system' ),
			'removed'  => __( 'Your vote was removed.', 'feature-request-system' ),
			'login'    => __( 'Log in to vote on this request.', 'feature-request-system' ),
			'closed'   => __( 'Voting is closed for this request.', 'feature-request-system' ),
			'disabled' => __( 'Voting is turned off for this board.', 'feature-request-system' ),
			'limit'    => __( 'Too many votes in a short time. Try again later.', 'feature-request-system' ),
			'missing'  => __( 'This request is not open for voting.', 'feature-request-system' ),
			'expired'  => __( 'This page has expired. Reload it and try again.', 'feature-request-system' ),
			'origin'   => __( 'Your vote could not be verified. Reload the page and try again.', 'feature-request-system' ),
			'error'    => __( 'Your vote could not be saved. Try again.', 'feature-request-system' ),
		];

		return $messages[ $code ] ?? '';
	}
}
