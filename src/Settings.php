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
	 * Default value of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			self::DEFAULT_BOARD            => 0,
			self::DEFAULT_STATUS           => 0,
			self::DELETE_DATA_ON_UNINSTALL => false,
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
