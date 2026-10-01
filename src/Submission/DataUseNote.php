<?php
/**
 * The "How we use your data" note.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Submission;

use FeatureRequestSystem\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Text the site owner writes for visitors who suggest a feature without an account.
 * The form shows it under the name and email fields.
 */
final class DataUseNote {

	/**
	 * HTML the note may contain: links and emphasis.
	 */
	private const TAGS = [
		'a'      => [ 'href' => true ],
		'strong' => [],
		'em'     => [],
		'br'     => [],
	];

	/**
	 * Removes everything from the text except links and emphasis.
	 *
	 * @param string $text Text as the site owner wrote it.
	 */
	public static function sanitize( string $text ): string {
		return trim( wp_kses( $text, self::TAGS ) );
	}

	/**
	 * Returns the note as paragraphs for the form, or '' when the site has none.
	 */
	public static function html(): string {
		$note = self::sanitize( (string) Settings::get( Settings::DATA_USE_NOTE ) );

		return '' === $note ? '' : wpautop( $note );
	}

	/**
	 * Suggests a note that describes what the plugin does with a visitor's details on this site.
	 */
	public static function suggestion(): string {
		$sentences = [
			__( 'We keep the name and email you enter with your request. Only our team can see them, and we use them only to contact you about your request.', 'feature-request-system' ),
			Akismet::is_connected() ? __( 'To stop spam, your request is checked by Akismet, which receives its text, your name, email address, IP address and browser details.', 'feature-request-system' ) : '',
			__( 'Ask us at any time to remove your details.', 'feature-request-system' ),
		];

		return implode( ' ', array_filter( $sentences ) );
	}
}
