<?php
/**
 * Spam checks for submitted requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Submission;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap checks that stop most automated submissions before they reach the database.
 *
 * - A trap field that people never see and bots tend to fill in.
 * - A signed timestamp from when the form was rendered: bots that post the form
 *   within seconds of loading it are refused, and the timestamp cannot be forged.
 * - A filter for captcha providers.
 *
 * The form's HTML stays the same for every visitor, so cached pages work; on a page
 * served from cache the timestamp is older, which only makes the timing check lenient.
 */
final class SpamGuard {

	/**
	 * Name of the trap field. People leave it empty; bots that fill every field do not.
	 */
	public const FIELD_TRAP = 'fr_website';

	/**
	 * Name of the field with the signed render time.
	 */
	public const FIELD_TOKEN = 'fr_token';

	/**
	 * Filter for captcha providers: return true, or a WP_Error explaining the failure.
	 * Receives the verdict so far and the submitted fields.
	 */
	public const FILTER_CAPTCHA = 'mwtfrs_verify_captcha';

	/**
	 * Filter for spam services such as Akismet: return true to hold a submission for review.
	 * Receives the verdict so far and the submission's title, description, name and email.
	 */
	public const FILTER_SPAM = 'mwtfrs_is_spam';

	/**
	 * Error code for a submission the checks refused.
	 */
	public const ERROR_REJECTED = 'mwtfrs_submission_rejected';

	/**
	 * Fewest seconds between rendering the form and submitting it.
	 */
	private const MIN_SECONDS = 3;

	/**
	 * Returns the token for a form rendered now.
	 *
	 * @param int|null $time Render time, for tests. Defaults to now.
	 */
	public static function token( ?int $time = null ): string {
		$time = (string) ( $time ?? time() );

		return $time . '.' . self::sign( $time );
	}

	/**
	 * Checks the trap field, the token and any captcha.
	 *
	 * @param array<string, mixed> $fields Submitted fields.
	 * @return true|WP_Error
	 */
	public static function check( array $fields ) {
		$trap  = $fields[ self::FIELD_TRAP ] ?? '';
		$token = $fields[ self::FIELD_TOKEN ] ?? '';

		if ( '' !== $trap || ! is_string( $token ) || ! self::token_is_valid( $token ) ) {
			return self::rejected();
		}

		$captcha = apply_filters( self::FILTER_CAPTCHA, true, $fields ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		return true === $captcha ? true : self::rejected();
	}

	/**
	 * Asks spam services whether a submission looks like spam.
	 *
	 * @param array{title: string, description: string, name: string, email: string} $submission Submitted request.
	 */
	public static function is_spam( array $submission ): bool {
		return (bool) apply_filters( self::FILTER_SPAM, false, $submission ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
	}

	/**
	 * Whether a token is genuine and old enough.
	 *
	 * @param string $token Token from the form.
	 */
	private static function token_is_valid( string $token ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return false;
		}

		return time() - (int) $parts[0] >= self::MIN_SECONDS;
	}

	/**
	 * Signs a value with a secret only this site knows.
	 *
	 * @param string $value Value to sign.
	 */
	private static function sign( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) );
	}

	/**
	 * The error for a refused submission. It does not say which check failed.
	 */
	private static function rejected(): WP_Error {
		return new WP_Error(
			self::ERROR_REJECTED,
			__( 'Your request could not be sent. Reload the page and try again.', 'feature-request-system' ),
			[ 'status' => 400 ]
		);
	}
}
