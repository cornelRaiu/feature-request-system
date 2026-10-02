<?php
/**
 * The switch that keeps a status change quiet.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Notifications\StatusNotifier;

defined( 'ABSPATH' ) || exit;

/**
 * A ticked checkbox next to the status on the edit screen, in Quick Edit and in Bulk Edit.
 * A moderator who unticks it changes the status without an email to the request's followers.
 *
 * Changes made anywhere else, such as the REST API or WP-CLI, are always announced. Code
 * can keep them quiet with the filter in StatusNotifier.
 */
final class NotifySwitch {

	/**
	 * Name of the checkbox. A hidden field before it sends 0 when the box is unticked.
	 */
	public const FIELD = 'mwtfrs_notify_followers';

	/**
	 * Adds the hook that answers whether a status change is announced.
	 */
	public static function add_hooks(): void {
		add_filter( StatusNotifier::FILTER_NOTIFY, [ self::class, 'respect' ], 10, 2 );
	}

	/**
	 * Returns the checkbox with its label.
	 *
	 * @param string $label Label next to the box.
	 * @param string $style Inline style for the label, where the screen's own style for labels does not suit a long one.
	 */
	public static function field( string $label, string $style = '' ): string {
		return sprintf(
			'<input type="hidden" name="%1$s" value="0"><label%3$s><input type="checkbox" name="%1$s" value="1" checked> %2$s</label>',
			esc_attr( self::FIELD ),
			esc_html( $label ),
			'' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''
		);
	}

	/**
	 * Says when the emails about a status change go out.
	 */
	public static function timing(): string {
		$minutes = (int) ceil( StatusNotifier::delay() / MINUTE_IN_SECONDS );
		if ( ! $minutes ) {
			return __( 'The emails go out right after the change.', 'feature-request-system' );
		}

		return sprintf(
			/* translators: %s: number of minutes. */
			_n(
				'The emails go out %s minute after the last change, so a status picked by mistake can still be corrected.',
				'The emails go out %s minutes after the last change, so a status picked by mistake can still be corrected.',
				$minutes,
				'feature-request-system'
			),
			number_format_i18n( $minutes )
		);
	}

	/**
	 * Keeps a status change quiet when the form that makes it has the box unticked.
	 *
	 * @param mixed $notify     Whether the change is announced, so far.
	 * @param int   $request_id Request whose status changes.
	 */
	public static function respect( $notify, int $request_id ): bool {
		return $notify && ! self::is_unticked_for( $request_id );
	}

	/**
	 * Whether the current request is a save of this request, from one of the three forms, with the box unticked.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function is_unticked_for( int $request_id ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The form's nonce is checked below, before the value counts.
		if ( ! isset( $_REQUEST[ self::FIELD ] ) || '0' !== $_REQUEST[ self::FIELD ] ) {
			return false;
		}

		$edit_screen = isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $request_id );

		return $edit_screen || StatusQuickEdit::is_quick_or_bulk_edit();
	}
}
