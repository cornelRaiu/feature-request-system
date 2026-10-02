<?php
/**
 * Suggested privacy policy text.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Privacy;

use FeatureRequestSystem\Submission\Akismet;
use FeatureRequestSystem\Voting\VoterIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * Offers site owners a text about this plugin for their privacy policy, under
 * Settings → Privacy → Policy Guide. It says what the plugin stores, as it works on
 * this site: the part about Akismet is only there when Akismet checks new requests.
 */
final class PolicyText {

	/**
	 * Adds the hook that hands the text to WordPress.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_init', [ self::class, 'add' ] );
	}

	/**
	 * Hands the text to WordPress's privacy policy guide.
	 */
	public static function add(): void {
		wp_add_privacy_policy_content( __( 'Feature Request System', 'feature-request-system' ), self::html() );
	}

	/**
	 * Returns the text, with a note for the site owner first. WordPress leaves the note
	 * out when the text is copied.
	 */
	public static function html(): string {
		$paragraphs = [
			__( 'When you suggest a feature while you are logged in, we store that the request came from your account. When you suggest a feature without an account, you can give your name and email address. We store them with your request. Only our team can see them, and we use them only to contact you about your request. A request is public once we approve it, without your name.', 'feature-request-system' ),
			sprintf(
				/* translators: %s: name of a cookie. */
				__( 'When you vote while you are logged in, we store your vote with your account. When you vote without an account, we store a cookie named %s in your browser for one year, so that the site remembers your votes. The cookie holds a random number and nothing else about you.', 'feature-request-system' ),
				VoterIdentity::COOKIE
			),
			__( 'When you are logged in and you vote for a request, suggest one or press its Follow button, we store that you follow that request, and we email you when its status changes. Every email has a link that stops these emails, and you can also stop them in your profile.', 'feature-request-system' ),
			__( 'To limit how often one visitor can vote or send requests, we count attempts per IP address for up to one hour. The count is stored under a scrambled form of the address that cannot be turned back into it. We do not store your IP address.', 'feature-request-system' ),
		];

		if ( Akismet::is_connected() ) {
			$paragraphs[] = sprintf(
				/* translators: %s: URL of Akismet's privacy policy. */
				__( 'New feature requests are checked by Akismet, an anti-spam service. It receives the text of the request, the name and email address you gave, your IP address and the name of your browser. Akismet has its own <a href="%s">privacy policy</a>.', 'feature-request-system' ),
				'https://akismet.com/privacy/'
			);
		}

		$html = '<p class="privacy-policy-tutorial">' . esc_html__( 'This plugin stores feature requests, votes, and which requests a user follows, in your site\'s database. The text below describes that. Leave out what your site does not use, such as the sentence about voting without an account when none of your boards allow it.', 'feature-request-system' ) . '</p>';

		foreach ( $paragraphs as $index => $paragraph ) {
			$html .= '<p>'
				. ( 0 === $index ? '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'feature-request-system' ) . ' </strong>' : '' )
				. wp_kses( $paragraph, [ 'a' => [ 'href' => [] ] ] )
				. '</p>';
		}

		return $html;
	}
}
