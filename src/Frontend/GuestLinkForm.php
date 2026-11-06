<?php
/**
 * The form for an email address next to a vote button.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Guests\Guests;
use FeatureRequestSystem\Security\RequestOrigin;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Submission\Turnstile;
use FeatureRequestSystem\Voting\VoteService;

defined( 'ABSPATH' ) || exit;

/**
 * On a board where visitors vote once they confirm their email, a visitor who has not
 * gives their address in a small form next to the vote button. The plugin emails them a
 * link, and their vote counts once they press the button on the link's page.
 *
 * With JavaScript the form is sent through the REST API. Without it, it posts to
 * admin-post.php and the visitor comes back to the request with a notice. The form is
 * the same for every visitor, so pages that show it can be cached.
 */
final class GuestLinkForm {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'mwtfrs_guest_link';

	/**
	 * Notice codes for the errors.
	 */
	private const ERROR_NOTICES = [
		Guests::ERROR_INVALID_EMAIL       => 'bad-email',
		Guests::ERROR_RATE_LIMITED        => 'link-limit',
		SpamGuard::ERROR_REJECTED         => 'rejected',
		VoteService::ERROR_NOT_FOUND      => 'missing',
		VoteService::ERROR_CLOSED         => 'closed',
		VoteService::ERROR_DISABLED       => 'disabled',
		VoteService::ERROR_LOGIN_REQUIRED => 'login',
		VoteService::ERROR_NOT_ALLOWED    => 'refused',
		VoteService::ERROR_LIMIT_REACHED  => 'votes-used',
		Turnstile::ERROR_FAILED           => 'link-captcha',
	];

	/**
	 * Adds the form handler. Logged-in users vote without it, but a form sent just after
	 * logging in elsewhere still works.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * What a template needs to render the form, besides what the vote form has.
	 *
	 * @return array{action: string, trap_field: string, token_field: string, token: string, max_length: int, captcha_field: string}
	 */
	public static function template_args(): array {
		return [
			'action'        => self::ACTION,
			'trap_field'    => SpamGuard::FIELD_TRAP,
			'token_field'   => SpamGuard::FIELD_TOKEN,
			'token'         => SpamGuard::token(),
			'max_length'    => SubmissionService::MAX_EMAIL,
			'captcha_field' => Turnstile::FIELD,
		];
	}

	/**
	 * The Turnstile site key for the form, or '' while the check is off. When it is on, this
	 * loads Cloudflare's script. The widget is drawn only when a visitor opens the form, so a
	 * board with many requests does not start a check for each of them.
	 *
	 * Visitors who are not logged in all get the same key, so cached pages stay correct.
	 */
	public static function captcha_key(): string {
		if ( ! Turnstile::applies() ) {
			return '';
		}

		Turnstile::enqueue();

		return Turnstile::site_key();
	}

	/**
	 * Handles the form post and redirects back to the request.
	 */
	public static function handle(): void {
		$input   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() checks the origin. Visitors get no nonce, so their pages can be cached.
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		wp_safe_redirect( self::process( $input, $origin, $referer ) );
		exit;
	}

	/**
	 * Asks for the link and returns the URL to send the visitor back to.
	 *
	 * @param array<string, mixed> $input   Posted fields: email, request_id, redirect_to and the spam check fields.
	 * @param string               $origin  Origin header.
	 * @param string               $referer Referer header.
	 */
	public static function process( array $input, string $origin, string $referer ): string {
		$request_id = absint( $input['request_id'] ?? 0 );
		$back       = wp_validate_redirect( esc_url_raw( (string) ( $input['redirect_to'] ?? '' ) ), home_url( '/' ) );

		if ( ! RequestOrigin::is_same_site( $origin, $referer ) ) {
			return RequestNotice::url( $back, 'origin', $request_id );
		}

		$email  = isset( $input['email'] ) && is_string( $input['email'] ) ? $input['email'] : '';
		$result = Guests::start_vote( $email, $request_id, $input );
		if ( is_wp_error( $result ) ) {
			return RequestNotice::url( $back, self::ERROR_NOTICES[ $result->get_error_code() ] ?? 'error', $request_id );
		}

		return RequestNotice::url( $back, 'link-sent', $request_id );
	}
}
