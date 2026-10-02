<?php
/**
 * Plugin settings storage.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single settings option, filling in defaults for keys that were never saved.
 */
final class Settings {

	/**
	 * Option that stores every setting in one array.
	 */
	public const OPTION = 'mwtfrs_settings';

	/**
	 * Term ID of the board that new requests go to when none is chosen.
	 */
	public const DEFAULT_BOARD = 'default_board';

	/**
	 * Term ID of the status that new requests start in.
	 */
	public const DEFAULT_STATUS = 'default_status';

	/**
	 * Whether uninstalling the plugin also deletes its data.
	 */
	public const DELETE_DATA_ON_UNINSTALL = 'delete_data_on_uninstall';

	/**
	 * Whether to email the site's moderators about every new request.
	 */
	public const NOTIFY_NEW_REQUESTS = 'notify_new_requests';

	/**
	 * Comma-separated addresses for new request emails. Empty means the site admin email.
	 */
	public const NOTIFY_EMAILS = 'notify_emails';

	/**
	 * Text shown to visitors who suggest a feature without an account, about how their
	 * name and email are used. Links and emphasis only. Empty shows nothing.
	 */
	public const DATA_USE_NOTE = 'data_use_note';

	/**
	 * Whether logged-in users can follow requests and get an email when a request's
	 * status changes. Off means nobody is emailed about a request they follow.
	 */
	public const NOTIFY_FOLLOWERS = 'notify_followers';

	/**
	 * Whether voting for a request also follows it.
	 */
	public const SUBSCRIBE_VOTERS = 'subscribe_voters';

	/**
	 * Default value of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			self::DEFAULT_BOARD            => 0,
			self::DEFAULT_STATUS           => 0,
			self::DELETE_DATA_ON_UNINSTALL => false,
			self::NOTIFY_NEW_REQUESTS      => true,
			self::NOTIFY_EMAILS            => '',
			self::DATA_USE_NOTE            => '',
			self::NOTIFY_FOLLOWERS         => true,
			self::SUBSCRIBE_VOTERS         => true,
		];
	}

	/**
	 * Returns one setting, or its default when it was never saved.
	 *
	 * @param string $key Setting key, one of this class's constants.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$settings = array_merge( self::defaults(), self::stored() );

		return $settings[ $key ] ?? null;
	}

	/**
	 * Saves one setting.
	 *
	 * @param string $key   Setting key, one of this class's constants.
	 * @param mixed  $value New value.
	 */
	public static function update( string $key, $value ): void {
		$settings         = self::stored();
		$settings[ $key ] = $value;

		update_option( self::OPTION, $settings );
	}

	/**
	 * Returns the saved settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}
}
