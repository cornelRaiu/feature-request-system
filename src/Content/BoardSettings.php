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
	 * Setting: who may vote on the board's requests. One of ANYONE, LOGGED_IN or NOBODY.
	 */
	public const VOTING = 'voting';

	/**
	 * Setting: who may submit new requests to the board. One of ANYONE, LOGGED_IN or NOBODY.
	 */
	public const SUBMITTING = 'submitting';

	/**
	 * Setting: whether new requests wait for approval before they appear.
	 */
	public const MODERATION = 'moderation';

	/**
	 * Everyone may take part, including visitors who are not logged in.
	 */
	public const ANYONE = 'anyone';

	/**
	 * Only logged-in users may take part. The default, so visitors can read but not vote or submit.
	 */
	public const LOGGED_IN = 'logged_in';

	/**
	 * Nobody may take part. The action is turned off for the board.
	 */
	public const NOBODY = 'nobody';

	/**
	 * Default value of every setting.
	 *
	 * @return array<string, string|bool>
	 */
	public static function defaults(): array {
		return [
			self::VOTING     => self::LOGGED_IN,
			self::SUBMITTING => self::LOGGED_IN,
			self::MODERATION => true,
		];
	}

	/**
	 * Returns a board's settings, using the default for anything missing or invalid.
	 *
	 * @param int $board_id Board term ID. 0 returns the defaults.
	 * @return array<string, string|bool>
	 */
	public static function get( int $board_id ): array {
		$stored = $board_id ? get_term_meta( $board_id, self::META, true ) : [];

		return self::sanitize( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	/**
	 * Who may vote on a board: ANYONE, LOGGED_IN or NOBODY.
	 *
	 * @param int $board_id Board term ID.
	 */
	public static function voting( int $board_id ): string {
		return (string) self::get( $board_id )[ self::VOTING ];
	}

	/**
	 * Who may submit requests to a board: ANYONE, LOGGED_IN or NOBODY.
	 *
	 * @param int $board_id Board term ID.
	 */
	public static function submitting( int $board_id ): string {
		return (string) self::get( $board_id )[ self::SUBMITTING ];
	}

	/**
	 * Whether a board's new requests wait for approval.
	 *
	 * @param int $board_id Board term ID.
	 */
	public static function moderated( int $board_id ): bool {
		return (bool) self::get( $board_id )[ self::MODERATION ];
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
	 * @param array<string, mixed>       $settings Raw settings.
	 * @param array<string, string|bool> $base     Valid settings to start from.
	 * @return array<string, string|bool>
	 */
	private static function sanitize( array $settings, array $base ): array {
		$clean = $base;

		foreach ( [ self::VOTING, self::SUBMITTING ] as $key ) {
			if ( in_array( $settings[ $key ] ?? null, [ self::ANYONE, self::LOGGED_IN, self::NOBODY ], true ) ) {
				$clean[ $key ] = $settings[ $key ];
			}
		}

		if ( isset( $settings[ self::MODERATION ] ) && is_bool( $settings[ self::MODERATION ] ) ) {
			$clean[ self::MODERATION ] = $settings[ self::MODERATION ];
		}

		return $clean;
	}
}
