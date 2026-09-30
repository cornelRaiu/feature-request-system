<?php
/**
 * Who is voting.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Voting;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the current visitor into a voter key: a hash of the user ID for logged-in users,
 * or of a random token kept in a cookie for guests.
 *
 * Guest identity is best-effort. Clearing cookies gives a guest a new identity, which is
 * why boards only accept guest votes when the site owner turns that on, and why guests
 * are also rate-limited by IP address.
 */
final class VoterIdentity {

	/**
	 * Cookie that holds a guest's voter token.
	 */
	public const COOKIE = 'mwtfrs_vid';

	/**
	 * Returns the current visitor's voter key.
	 *
	 * @param bool $create Whether to give a guest without a token a new one.
	 * @return string SHA-256 hex digest, or '' for a guest without a token.
	 */
	public static function key( bool $create = false ): string {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return hash( 'sha256', 'u:' . $user_id );
		}

		$token = self::guest_token();
		if ( '' === $token && $create ) {
			$token = self::issue_guest_token();
		}

		return '' === $token ? '' : hash( 'sha256', 'g:' . $token );
	}

	/**
	 * Returns the guest token from the cookie, or '' when it is missing or malformed.
	 */
	private static function guest_token(): string {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token ) ? $token : '';
	}

	/**
	 * Creates a guest token and sends it as a first-party cookie that scripts cannot read.
	 */
	private static function issue_guest_token(): string {
		$token = bin2hex( random_bytes( 16 ) );

		// The rest of this request treats the visitor as the same guest.
		$_COOKIE[ self::COOKIE ] = $token;

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$token,
				[
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH,
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}

		return $token;
	}
}
