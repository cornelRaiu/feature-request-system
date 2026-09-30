<?php
/**
 * Per-board settings.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Settings stored on each board. A board without saved settings uses the defaults.
 */
final class BoardSettings {

	/**
	 * Term meta holding the board's settings array.
	 */
	public const META = '_mwtfrs_board_settings';

	/**
	 * Setting: who may vote on the board's requests.
	 */
	public const VOTING = 'voting';

	/**
	 * Everyone may vote, including visitors who are not logged in.
	 */
	public const ANYONE = 'anyone';

	/**
	 * Only logged-in users may vote. The default, so visitors can read but not vote.
	 */
	public const LOGGED_IN = 'logged_in';

	/**
	 * Nobody may vote. Voting is turned off for the board.
	 */
	public const NOBODY = 'nobody';

	/**
	 * Default value of every setting.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return [
			self::VOTING => self::LOGGED_IN,
		];
	}

	/**
	 * Returns a board's settings, using the default for anything missing or invalid.
	 *
	 * @param int $board_id Board term ID. 0 returns the defaults.
	 * @return array<string, string>
	 */
	public static function get( int $board_id ): array {
		$stored = $board_id ? get_term_meta( $board_id, self::META, true ) : [];

		return self::sanitize( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	/**
	 * Saves settings for a board. Settings not passed, and invalid values, leave the current value in place.
	 *
	 * @param int                  $board_id Board term ID.
	 * @param array<string, mixed> $settings Settings to change.
	 */
	public static function update( int $board_id, array $settings ): void {
		update_term_meta( $board_id, self::META, self::sanitize( $settings, self::get( $board_id ) ) );
	}

	/**
	 * Applies the valid values from $settings on top of $base. Unknown keys and invalid values are dropped.
	 *
	 * @param array<string, mixed>  $settings Raw settings.
	 * @param array<string, string> $base     Valid settings to start from.
	 * @return array<string, string>
	 */
	private static function sanitize( array $settings, array $base ): array {
		$clean  = $base;
		$voting = $settings[ self::VOTING ] ?? null;

		if ( in_array( $voting, [ self::ANYONE, self::LOGGED_IN, self::NOBODY ], true ) ) {
			$clean[ self::VOTING ] = $voting;
		}

		return $clean;
	}
}
