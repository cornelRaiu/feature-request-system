<?php
/**
 * Notices about a request after a form post.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Carries what happened to a request through a redirect, for visitors who vote or follow
 * without JavaScript. The form handler sends the visitor back to the page with a notice
 * code in the URL, and the page shows the message for that code next to the request.
 */
final class RequestNotice {

	/**
	 * URL parameter with the notice code.
	 */
	public const PARAM = 'fr-notice';

	/**
	 * URL parameter with the request the notice is about.
	 */
	public const PARAM_REQUEST = 'fr-request';

	/**
	 * Codes of the notices that end with a link to log in.
	 */
	private const LOGIN_CODES = [ 'login', 'follow-login' ];

	/**
	 * Returns the URL that shows a notice next to a request.
	 *
	 * @param string $page_url   Page that shows the request.
	 * @param string $code       Notice code.
	 * @param int    $request_id Request ID.
	 */
	public static function url( string $page_url, string $code, int $request_id ): string {
		return add_query_arg(
			[
				self::PARAM         => $code,
				self::PARAM_REQUEST => $request_id,
			],
			$page_url
		) . '#' . RequestItem::dom_id( $request_id );
	}

	/**
	 * Returns the notice from the current URL, if there is one.
	 *
	 * @return array{request: int, message: string, login: bool}|null
	 */
	public static function from_request(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only picks a message to display.
		$code    = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_key( $_GET[ self::PARAM ] ) : '';
		$request = isset( $_GET[ self::PARAM_REQUEST ] ) ? absint( $_GET[ self::PARAM_REQUEST ] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = self::message( $code );
		if ( '' === $message || ! $request ) {
			return null;
		}

		return [
			'request' => $request,
			'message' => $message,
			'login'   => in_array( $code, self::LOGIN_CODES, true ),
		];
	}

	/**
	 * Returns the message for a notice code, or '' for an unknown code.
	 *
	 * @param string $code Notice code.
	 */
	private static function message( string $code ): string {
		$messages = [
			'added'        => __( 'Your vote was added.', 'feature-request-system' ),
			'removed'      => __( 'Your vote was removed.', 'feature-request-system' ),
			'login'        => __( 'Log in to vote on this request.', 'feature-request-system' ),
			'closed'       => __( 'Voting is closed for this request.', 'feature-request-system' ),
			'disabled'     => __( 'Voting is turned off for this board.', 'feature-request-system' ),
			'limit'        => __( 'Too many votes in a short time. Try again later.', 'feature-request-system' ),
			'missing'      => __( 'This request is not open for voting.', 'feature-request-system' ),
			'expired'      => __( 'This page has expired. Reload it and try again.', 'feature-request-system' ),
			'origin'       => __( 'Your vote could not be verified. Reload the page and try again.', 'feature-request-system' ),
			'error'        => __( 'Your vote could not be saved. Try again.', 'feature-request-system' ),
			'following'    => __( 'You now follow this request.', 'feature-request-system' ),
			'unfollowed'   => __( 'You no longer follow this request.', 'feature-request-system' ),
			'follow-login' => __( 'Log in to follow this request.', 'feature-request-system' ),
			'follow-error' => __( 'That could not be saved. Try again.', 'feature-request-system' ),
		];

		return $messages[ $code ] ?? '';
	}
}
