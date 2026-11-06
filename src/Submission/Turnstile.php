<?php
/**
 * Cloudflare Turnstile check for submitted requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Submission;

use FeatureRequestSystem\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Puts a Cloudflare Turnstile widget on the form and checks its answer, once the site
 * has saved both keys of a widget on the settings page.
 *
 * The widget is a script from Cloudflare, so with Turnstile on the form needs JavaScript.
 * Its answer is a token in a field of the form, which Cloudflare's siteverify endpoint
 * confirms. A token can be used once, and a refusal from Cloudflare, or no answer at all,
 * refuses the submission.
 */
final class Turnstile {

	/**
	 * Field the widget puts its token in.
	 */
	public const FIELD = 'cf-turnstile-response';

	/**
	 * Error code for a submission without a token that Cloudflare confirmed.
	 */
	public const ERROR_FAILED = 'mwtfrs_captcha_failed';

	/**
	 * Handle of Cloudflare's script.
	 */
	public const SCRIPT = 'mwtfrs-turnstile';

	/**
	 * Cloudflare's script. It must be loaded from this address, as it is: Cloudflare
	 * changes it, and a copy or a version parameter would break the widget.
	 */
	private const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- The captcha service's own script, which cannot be bundled; disclosed in the readme and loaded only once the site saves its keys.

	/**
	 * Cloudflare's endpoint that confirms a token.
	 */
	private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- The captcha service's API, called from the server; nothing is loaded from it.

	/**
	 * Longest token Cloudflare issues.
	 */
	private const MAX_TOKEN = 2048;

	/**
	 * Narrowest width, in pixels, of the widget at its flexible size. Cloudflare's compact size is 150.
	 */
	private const MIN_WIDTH = 300;

	/**
	 * Hooks into the captcha check.
	 */
	public static function add_hooks(): void {
		add_filter( SpamGuard::FILTER_CAPTCHA, [ self::class, 'check' ], 10, 2 );
	}

	/**
	 * Whether the site saved both keys.
	 */
	public static function is_active(): bool {
		return '' !== self::site_key() && '' !== (string) Settings::get( Settings::TURNSTILE_SECRET_KEY );
	}

	/**
	 * Whether the current visitor gets the check: every visitor without an account, and
	 * logged-in users when the site says so.
	 */
	public static function applies(): bool {
		return self::is_active() && ( ! is_user_logged_in() || Settings::get( Settings::TURNSTILE_LOGGED_IN ) );
	}

	/**
	 * The site key, which the widget on the form is made from.
	 */
	public static function site_key(): string {
		return (string) Settings::get( Settings::TURNSTILE_SITE_KEY );
	}

	/**
	 * Loads Cloudflare's script on the page.
	 */
	public static function enqueue(): void {
		if ( wp_script_is( self::SCRIPT, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT,
			self::SCRIPT_URL,
			[],
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare's script must load from its address as it is, without a version.
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// The widget is at least 300 pixels wide. Where a form is narrower, as on a phone, it
		// takes its compact size, so nothing sticks out of the page. This runs in the footer,
		// after the forms and before Cloudflare's script draws the widgets.
		wp_add_inline_script(
			self::SCRIPT,
			sprintf(
				'document.querySelectorAll(".mwtfrs-submit .cf-turnstile").forEach(function(w){if(w.clientWidth<%d){w.setAttribute("data-size","compact");}});',
				self::MIN_WIDTH
			),
			'before'
		);
	}

	/**
	 * Refuses a submission whose token Cloudflare does not confirm.
	 *
	 * @param true|WP_Error|mixed  $verdict Verdict so far.
	 * @param array<string, mixed> $fields  Submitted fields.
	 * @return true|WP_Error|mixed
	 */
	public static function check( $verdict, array $fields ) {
		if ( true !== $verdict || ! self::applies() ) {
			return $verdict;
		}

		$token = $fields[ self::FIELD ] ?? '';

		return is_string( $token ) && self::confirmed( $token ) ? true : self::refused();
	}

	/**
	 * Asks Cloudflare whether a token is genuine and unused.
	 *
	 * @param string $token Token from the form.
	 */
	private static function confirmed( string $token ): bool {
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN ) {
			return false;
		}

		$response = wp_remote_post(
			self::SITEVERIFY_URL,
			[
				'timeout' => 10,
				'body'    => [
					'secret'   => (string) Settings::get( Settings::TURNSTILE_SECRET_KEY ),
					'response' => $token,
				],
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $result ) && true === ( $result['success'] ?? false );
	}

	/**
	 * The error for a submission without a confirmed token. The visitor can fix it, so it says how.
	 */
	private static function refused(): WP_Error {
		return new WP_Error(
			self::ERROR_FAILED,
			__( 'Complete the spam check above the button, then send your request again.', 'feature-request-system' ),
			[ 'status' => 400 ]
		);
	}
}
