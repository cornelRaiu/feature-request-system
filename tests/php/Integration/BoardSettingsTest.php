<?php
/**
 * Board settings tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;

/**
 * Per-board voting rules.
 */
final class BoardSettingsTest extends TestCase {

	public function test_boards_let_only_logged_in_users_vote_by_default(): void {
		$this->assertSame( BoardSettings::LOGGED_IN, BoardSettings::get( $this->board() )[ BoardSettings::VOTING ] );
		$this->assertSame( BoardSettings::LOGGED_IN, BoardSettings::get( 0 )[ BoardSettings::VOTING ] );
	}

	public function test_update_saves_a_valid_value(): void {
		BoardSettings::update( $this->board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );

		$this->assertSame( BoardSettings::ANYONE, BoardSettings::get( $this->board() )[ BoardSettings::VOTING ] );
	}

	public function test_update_ignores_invalid_values_and_keeps_the_current_one(): void {
		BoardSettings::update( $this->board(), [ BoardSettings::VOTING => BoardSettings::NOBODY ] );

		BoardSettings::update(
			$this->board(),
			[
				BoardSettings::VOTING => 'everyone',
				'unknown'             => 'x',
			]
		);

		$this->assertSame( [ BoardSettings::VOTING => BoardSettings::NOBODY ], BoardSettings::get( $this->board() ) );
	}

	public function test_corrupt_stored_settings_fall_back_to_the_defaults(): void {
		update_term_meta( $this->board(), BoardSettings::META, 'not an array' );

		$this->assertSame( BoardSettings::defaults(), BoardSettings::get( $this->board() ) );
	}

	/**
	 * Returns the default board's ID.
	 */
	private function board(): int {
		return RequestTerms::default_term( BoardTaxonomy::NAME );
	}
}
