<?php
/**
 * Following without JavaScript.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Notifications\Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Follow button's plain form post, used when JavaScript is off or has not loaded.
 *
 * Submitting the form makes the user follow the request, or stop if they already do,
 * then sends them back to the request's page with a notice saying what happened.
 */
final class FollowForm {

	/**
	 * The admin-post action, also used as the nonce action.
	 */
	public const ACTION = 'mwtfrs_follow';

	/**
	 * Adds the form handler. Visitors whose login ended are sent back with a notice.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * What a template needs to render the form. Only logged-in users get one.
	 *
	 * @param string $return_url Page to come back to after following without JavaScript.
	 * @return array{url: string, action: string, nonce: string, redirect_to: string}
	 */
	public static function template_args( string $return_url ): array {
		return [
			'url'         => admin_url( 'admin-post.php' ),
			'action'      => self::ACTION,
			'nonce'       => wp_create_nonce( self::ACTION ),
			'redirect_to' => $return_url,
		];
	}

	/**
	 * Handles the form post and redirects back to the request's page.
	 */
	public static function handle(): void {
		wp_safe_redirect( self::process( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() checks the nonce.
		exit;
	}

	/**
	 * Follows or stops following, and returns the URL to send the user back to.
	 *
	 * @param array<string, mixed> $input Posted fields: request_id, redirect_to and _wpnonce.
	 */
	public static function process( array $input ): string {
		$request_id = absint( $input['request_id'] ?? 0 );
		$back       = wp_validate_redirect( esc_url_raw( (string) ( $input['redirect_to'] ?? '' ) ), home_url( '/' ) );

		return RequestNotice::url( $back, self::toggle( $request_id, (string) ( $input['_wpnonce'] ?? '' ) ), $request_id );
	}

	/**
	 * Checks the request is genuine, then follows or stops following.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $nonce      Nonce from the form.
	 * @return string Notice code.
	 */
	private static function toggle( int $request_id, string $nonce ): string {
		if ( ! is_user_logged_in() ) {
			return 'follow-login';
		}
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return 'expired';
		}

		$result = Subscriptions::is_following( $request_id, get_current_user_id() )
			? Subscriptions::unfollow( $request_id )
			: Subscriptions::follow( $request_id );

		if ( is_wp_error( $result ) ) {
			return 'follow-error';
		}

		return $result['following'] ? 'following' : 'unfollowed';
	}
}
