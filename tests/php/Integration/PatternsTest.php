<?php
/**
 * Block pattern tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\RequestCard;
use FeatureRequestSystem\Frontend\RequestView;
use FeatureRequestSystem\Frontend\SubmitForm;
use WP_Block_Patterns_Registry;
use WP_Block_Type_Registry;

/**
 * The patterns that put the plugin's blocks on a page.
 */
final class PatternsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->forget_invitation();
	}

	public function tear_down(): void {
		$this->forget_invitation();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_every_pattern_is_in_the_plugins_category_and_has_nothing_outside_its_blocks(): void {
		Blocks::register_invitation_pattern();
		$names = [ Blocks::PATTERN, Blocks::PATTERN_COLUMNS, Blocks::PATTERN_MOST_WANTED, Blocks::PATTERN_INVITATION ];

		foreach ( $names as $name ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $name );

			$this->assertContains( Blocks::PATTERN_CATEGORY, $pattern['categories'], $name );
			$this->assertNotSame( '', $pattern['description'], $name );
			$this->assertSame( [], $this->loose_html( parse_blocks( $pattern['content'] ) ), $name . ': nothing sits outside a block.' );
		}
	}

	public function test_a_pattern_puts_the_form_in_a_column_beside_the_board(): void {
		$blocks = $this->blocks_of( Blocks::PATTERN_COLUMNS );

		$this->assertSame( [ 'core/columns' ], wp_list_pluck( $blocks, 'blockName' ) );
		$this->assertSame( 'wide', $blocks[0]['attrs']['align'], 'In the width of the text, the form would be too narrow.' );

		$columns = $this->named( $blocks[0]['innerBlocks'] );
		$this->assertSame( [ 'core/column', 'core/column' ], wp_list_pluck( $columns, 'blockName' ) );
		$this->assertSame( [ SubmitForm::BLOCK ], wp_list_pluck( $this->named( $columns[0]['innerBlocks'] ), 'blockName' ) );
		$this->assertSame( [ 'core/heading', Board::BLOCK ], wp_list_pluck( $this->named( $columns[1]['innerBlocks'] ), 'blockName' ) );
		$this->assertLessThan( (float) $columns[1]['attrs']['width'], (float) $columns[0]['attrs']['width'], 'The form has the narrower column.' );
	}

	public function test_a_pattern_puts_a_heading_above_the_five_most_voted_requests(): void {
		$blocks = $this->blocks_of( Blocks::PATTERN_MOST_WANTED );

		$this->assertSame( [ 'core/group' ], wp_list_pluck( $blocks, 'blockName' ) );
		$inside = $this->named( $blocks[0]['innerBlocks'] );
		$this->assertSame( [ 'core/heading', Board::BLOCK ], wp_list_pluck( $inside, 'blockName' ) );
		$this->assertSame(
			[
				'compact' => true,
				'perPage' => 5,
			],
			$inside[1]['attrs']
		);
		$this->assertStringContainsString( '>Most wanted</h2>', $inside[0]['innerHTML'] );
	}

	public function test_the_invitation_leads_to_the_page_that_shows_the_default_board(): void {
		Blocks::register_invitation_pattern();
		$blocks = $this->blocks_of( Blocks::PATTERN_INVITATION );
		$this->assertSame( [ 'core/heading', 'core/paragraph', 'core/buttons' ], wp_list_pluck( $this->named( $blocks[0]['innerBlocks'] ), 'blockName' ) );
		$this->assertStringContainsString( '<a class="wp-block-button__link wp-element-button">Suggest a feature</a>', $this->content_of( Blocks::PATTERN_INVITATION ), 'No page shows the board yet, so the button has no link.' );
		$this->assertContains( 'call-to-action', WP_Block_Patterns_Registry::get_instance()->get_registered( Blocks::PATTERN_INVITATION )['categories'], 'Also among the calls to action of WordPress.' );

		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		BoardSettings::update( $this->default_board(), [ BoardSettings::PAGE => $page ] );
		$this->forget_invitation();
		Blocks::register_invitation_pattern();

		$this->assertStringContainsString( '<a class="wp-block-button__link wp-element-button" href="' . esc_url( get_permalink( $page ) ) . '">Suggest a feature</a>', $this->content_of( Blocks::PATTERN_INVITATION ) );
	}

	/**
	 * Finding the board's page takes the database, and a visitor's page view has no use for the pattern.
	 */
	public function test_the_invitation_is_registered_only_where_patterns_are_listed(): void {
		$registry = WP_Block_Patterns_Registry::get_instance();

		$this->register_again();
		$this->assertFalse( $registry->is_registered( Blocks::PATTERN_INVITATION ), 'Not for a visitor.' );
		$this->assertTrue( $registry->is_registered( Blocks::PATTERN_MOST_WANTED ) );
		$this->assertSame( 10, has_action( 'rest_api_init', [ Blocks::class, 'register_invitation_pattern' ] ), 'For the REST API, which the editor asks for patterns.' );

		set_current_screen( 'dashboard' );
		$this->register_again();
		$this->assertTrue( $registry->is_registered( Blocks::PATTERN_INVITATION ), 'In the admin.' );
	}

	/**
	 * Runs the registration that WordPress runs on init, for blocks that are not registered yet.
	 */
	private function register_again(): void {
		foreach ( [ Board::BLOCK, SubmitForm::BLOCK, RequestView::BLOCK, RequestCard::BLOCK ] as $name ) {
			WP_Block_Type_Registry::get_instance()->unregister( $name );
		}
		Blocks::register();
	}

	/**
	 * Unregisters the invitation, which a test before this one may have registered.
	 */
	private function forget_invitation(): void {
		if ( WP_Block_Patterns_Registry::get_instance()->is_registered( Blocks::PATTERN_INVITATION ) ) {
			unregister_block_pattern( Blocks::PATTERN_INVITATION );
		}
	}

	/**
	 * Returns the markup of a pattern.
	 *
	 * @param string $name Pattern name.
	 */
	private function content_of( string $name ): string {
		return (string) WP_Block_Patterns_Registry::get_instance()->get_registered( $name )['content'];
	}

	/**
	 * Returns the blocks of a pattern, without the white space between them.
	 *
	 * @param string $name Pattern name.
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks_of( string $name ): array {
		return $this->named( parse_blocks( $this->content_of( $name ) ) );
	}

	/**
	 * Leaves out the white space that the block parser returns as blocks without a name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function named( array $blocks ): array {
		return array_values( array_filter( $blocks, static fn ( array $block ): bool => null !== $block['blockName'] ) );
	}

	/**
	 * Returns the HTML that sits outside every block, at any depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	private function loose_html( array $blocks ): array {
		$loose = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' !== trim( $block['innerHTML'] ) ) {
				$loose[] = $block['innerHTML'];
			}
			$loose = array_merge( $loose, $this->loose_html( $block['innerBlocks'] ) );
		}

		return $loose;
	}
}
