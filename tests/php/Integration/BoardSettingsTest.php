<?php
/**
 * Board settings tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
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

		$this->assertSame( BoardSettings::NOBODY, BoardSettings::voting( $this->board() ) );
		$this->assertSame( array_keys( BoardSettings::defaults() ), array_keys( BoardSettings::get( $this->board() ) ), 'Unknown keys are dropped.' );
	}

	public function test_boards_take_new_requests_from_logged_in_users_and_hold_them_for_approval_by_default(): void {
		$this->assertSame( BoardSettings::LOGGED_IN, BoardSettings::submitting( $this->board() ) );
		$this->assertTrue( BoardSettings::moderated( $this->board() ) );
	}

	public function test_submitting_and_moderation_can_be_changed(): void {
		BoardSettings::update(
			$this->board(),
			[
				BoardSettings::SUBMITTING => BoardSettings::ANYONE,
				BoardSettings::MODERATION => false,
			]
		);

		$this->assertSame( BoardSettings::ANYONE, BoardSettings::submitting( $this->board() ) );
		$this->assertFalse( BoardSettings::moderated( $this->board() ) );
		$this->assertSame( BoardSettings::LOGGED_IN, BoardSettings::voting( $this->board() ), 'Other settings keep their value.' );
	}

	public function test_moderation_only_accepts_true_or_false(): void {
		BoardSettings::update( $this->board(), [ BoardSettings::MODERATION => 'no' ] );

		$this->assertTrue( BoardSettings::moderated( $this->board() ) );
	}

	public function test_a_board_can_be_limited_to_some_categories(): void {
		$reports = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		$billing = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		$this->assertSame( [], BoardSettings::categories( $this->board() ), 'Not limited by default.' );
		$this->assertSame( [], BoardSettings::defaults()[ BoardSettings::CATEGORIES ] );

		BoardSettings::update( $this->board(), [ BoardSettings::CATEGORIES => [ (string) $reports, $reports, 0, 'abc', $billing ] ] );
		$this->assertSame( [ $reports, $billing ], BoardSettings::get( $this->board() )[ BoardSettings::CATEGORIES ], 'Stored as term IDs, each once.' );
		$this->assertEqualsCanonicalizing( [ $reports, $billing ], BoardSettings::categories( $this->board() ) );

		BoardSettings::update( $this->board(), [ BoardSettings::CATEGORIES => 'everything' ] );
		$this->assertEqualsCanonicalizing( [ $reports, $billing ], BoardSettings::categories( $this->board() ), 'An invalid value keeps the current one.' );

		BoardSettings::update( $this->board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$this->assertEqualsCanonicalizing( [ $reports, $billing ], BoardSettings::categories( $this->board() ), 'Other settings leave it alone.' );
	}

	public function test_deleted_categories_no_longer_limit_a_board(): void {
		$reports = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		$billing = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		BoardSettings::update( $this->board(), [ BoardSettings::CATEGORIES => [ $reports, $billing ] ] );

		wp_delete_term( $billing, CategoryTaxonomy::NAME );
		$this->assertSame( [ $reports ], BoardSettings::categories( $this->board() ) );

		wp_delete_term( $reports, CategoryTaxonomy::NAME );
		$this->assertSame( [], BoardSettings::categories( $this->board() ), 'With all of them gone, the board offers every category again.' );

		BoardSettings::update( $this->board(), [ BoardSettings::CATEGORIES => [] ] );
		$this->assertSame( [], BoardSettings::get( $this->board() )[ BoardSettings::CATEGORIES ] );
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
