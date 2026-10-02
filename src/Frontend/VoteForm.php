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
 * then sends the visitor back to the page they voted on with a notice saying what happened.
 */
final class VoteForm {

	/**
	 * The admin-post action, also used as the nonce action for logged-in users.
	 */
	public const ACTION = 'mwtfrs_vote';

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
	 * What a template needs to render vote forms.
	 *
	 * @param string $return_url Page to come back to after a vote without JavaScript.
	 * @return array{url: string, action: string, nonce: string, redirect_to: string}
	 */
	public static function template_args( string $return_url ): array {
		return [
			'url'         => admin_url( 'admin-post.php' ),
			'action'      => self::ACTION,
			// Guests get no nonce, so their pages can be cached. Their posts are checked by origin.
			'nonce'       => is_user_logged_in() ? wp_create_nonce( self::ACTION ) : '',
			'redirect_to' => $return_url,
		];
	}

	/**
	 * Handles the form post and redirects back to the page the vote came from.
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

		return RequestNotice::url( $back, self::toggle( $request_id, (string) ( $input['_wpnonce'] ?? '' ), $origin, $referer ), $request_id );
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
}
