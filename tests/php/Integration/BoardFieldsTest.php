<?php
/**
 * Board settings screen and board page tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\BoardFields;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Frontend\Board;

/**
 * A board's settings on the Boards screens, and the page that shows the board.
 */
final class BoardFieldsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_POST = [];
		$_GET  = [];
	}

	public function tear_down(): void {
		$_POST = [];
		$_GET  = [];
		parent::tear_down();
	}

	public function test_edit_form_shows_the_boards_settings(): void {
		$page = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Roadmap',
			]
		);
		BoardSettings::update(
			$this->default_board(),
			[
				BoardSettings::VOTING     => BoardSettings::ANYONE,
				BoardSettings::MODERATION => false,
				BoardSettings::PAGE       => $page,
			]
		);

		ob_start();
		do_action( BoardTaxonomy::NAME . '_edit_form_fields', get_term( $this->default_board() ), BoardTaxonomy::NAME );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<select id="mwtfrs-board-voting" name="' . BoardFields::FIELD . '[' . BoardSettings::VOTING . ']">', $html );
		$this->assertStringContainsString( '<option value="anyone" selected=\'selected\'>Everyone, including visitors without an account</option>', $html );
		$this->assertStringContainsString( '<option value="logged_in" selected=\'selected\'>Logged-in users</option>', $html, 'Who can suggest features keeps its default.' );
		$this->assertStringContainsString( 'name="' . BoardFields::FIELD . '[' . BoardSettings::MODERATION . ']" value="1"> Hold new requests', $html, 'Unticked.' );
		$this->assertMatchesRegularExpression( '/<option class="level-0" value="' . $page . '" selected="selected">Roadmap<\/option>/', $html );
		$this->assertStringContainsString( 'name="' . BoardFields::NONCE_FIELD . '" value="' . wp_create_nonce( BoardFields::NONCE_ACTION ) . '"', $html );
	}

	public function test_add_form_starts_from_the_defaults(): void {
		ob_start();
		do_action( BoardTaxonomy::NAME . '_add_form_fields', BoardTaxonomy::NAME );
		$html = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $html, '<option value="logged_in" selected=\'selected\'>' ), 'Voting and suggesting are for logged-in users.' );
		$this->assertStringContainsString( 'value="1" checked=\'checked\'> Hold new requests', $html );
		$this->assertStringContainsString( 'name="' . BoardFields::NONCE_FIELD . '"', $html );
	}

	public function test_saving_the_form_updates_the_board(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$page  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$_POST = [
			BoardFields::NONCE_FIELD => wp_create_nonce( BoardFields::NONCE_ACTION ),
			BoardFields::FIELD       => [
				BoardSettings::VOTING     => BoardSettings::ANYONE,
				BoardSettings::SUBMITTING => BoardSettings::NOBODY,
				BoardSettings::PAGE       => (string) $page,
			],
		];

		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Ideas' ] );

		$this->assertSame(
			[
				BoardSettings::VOTING     => BoardSettings::ANYONE,
				BoardSettings::SUBMITTING => BoardSettings::NOBODY,
				BoardSettings::MODERATION => false,
				BoardSettings::PAGE       => $page,
				BoardSettings::CATEGORIES => [],
			],
			BoardSettings::get( $this->default_board() ),
			'An unticked moderation box turns moderation off.'
		);
	}

	public function test_the_forms_offer_every_category_and_show_the_ones_the_board_is_limited_to(): void {
		ob_start();
		do_action( BoardTaxonomy::NAME . '_add_form_fields', BoardTaxonomy::NAME );
		$this->assertStringContainsString( 'No categories yet.', (string) ob_get_clean() );

		$billing = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Billing',
			]
		);
		$reports = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Reports & exports',
			]
		);
		BoardSettings::update( $this->default_board(), [ BoardSettings::CATEGORIES => [ $reports ] ] );
		$name = BoardFields::FIELD . '[' . BoardSettings::CATEGORIES . '][]';

		ob_start();
		do_action( BoardTaxonomy::NAME . '_edit_form_fields', get_term( $this->default_board() ), BoardTaxonomy::NAME );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<th scope="row">Categories</th>', $html, 'The title names the group. It is not the label of one box.' );
		$this->assertStringContainsString( '<legend class="screen-reader-text">Categories</legend>', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="' . $name . '" value="' . $billing . '"> Billing</label>', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="' . $name . '" value="' . $reports . '" checked=\'checked\'> Reports &amp; exports</label>', $html );
		$this->assertStringContainsString( 'Tick none to offer every category.', $html );

		ob_start();
		do_action( BoardTaxonomy::NAME . '_add_form_fields', BoardTaxonomy::NAME );
		$add = (string) ob_get_clean();

		$this->assertStringContainsString( '<span style="display:block;padding:2px 0">Categories</span><fieldset id="mwtfrs-board-categories">', $add );
		$this->assertStringContainsString( '<input type="checkbox" name="' . $name . '" value="' . $reports . '"> Reports &amp; exports</label>', $add, 'Nothing is ticked for a new board.' );
		foreach ( [ $html, $add ] as $form ) {
			$this->assertStringNotContainsString( 'for="mwtfrs-board-categories"', $form, 'A group of boxes is not what a label points at.' );
		}
	}

	public function test_a_category_name_cannot_add_markup_to_the_forms(): void {
		global $wpdb;

		$category = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		// WordPress cleans names when it saves them. This one reached the database another way.
		$wpdb->update( $wpdb->terms, [ 'name' => 'Bill<script>alert(1)</script>ing' ], [ 'term_id' => $category ] );
		clean_term_cache( $category, CategoryTaxonomy::NAME );

		ob_start();
		do_action( BoardTaxonomy::NAME . '_edit_form_fields', get_term( $this->default_board() ), BoardTaxonomy::NAME );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Bill&lt;script&gt;alert(1)&lt;/script&gt;ing', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_saving_the_form_limits_the_board_to_the_ticked_categories(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$kept    = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		$dropped = self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		BoardSettings::update( $this->default_board(), [ BoardSettings::CATEGORIES => [ $kept, $dropped ] ] );
		$_POST = [
			BoardFields::NONCE_FIELD => wp_create_nonce( BoardFields::NONCE_ACTION ),
			BoardFields::FIELD       => [ BoardSettings::CATEGORIES => [ (string) $kept ] ],
		];

		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Ideas' ] );
		$this->assertSame( [ $kept ], BoardSettings::categories( $this->default_board() ) );

		$_POST[ BoardFields::FIELD ] = [];
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Ideas again' ] );
		$this->assertSame( [], BoardSettings::get( $this->default_board() )[ BoardSettings::CATEGORIES ], 'No ticked box means every category.' );
	}

	public function test_a_new_board_gets_the_settings_chosen_on_the_add_form(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$_POST = [
			BoardFields::NONCE_FIELD => wp_create_nonce( BoardFields::NONCE_ACTION ),
			BoardFields::FIELD       => [
				BoardSettings::VOTING     => BoardSettings::ANYONE,
				BoardSettings::SUBMITTING => BoardSettings::ANYONE,
				BoardSettings::MODERATION => '1',
			],
		];

		$board = wp_insert_term( 'Mobile app', BoardTaxonomy::NAME );

		$this->assertSame( BoardSettings::ANYONE, BoardSettings::submitting( (int) $board['term_id'] ) );
		$this->assertTrue( BoardSettings::moderated( (int) $board['term_id'] ) );
	}

	public function test_boards_changed_in_other_ways_keep_their_settings(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$before = BoardSettings::get( $this->default_board() );
		$fields = [
			BoardSettings::VOTING     => BoardSettings::NOBODY,
			BoardSettings::SUBMITTING => BoardSettings::NOBODY,
		];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Renamed by code' ] );
		$this->assertSame( $before, BoardSettings::get( $this->default_board() ), 'An update without the form, as from the REST API or WP-CLI.' );

		$_POST = [
			BoardFields::NONCE_FIELD => 'forged',
			BoardFields::FIELD       => $fields,
		];
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Forged form' ] );
		$this->assertSame( $before, BoardSettings::get( $this->default_board() ), 'A form without a valid nonce.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST[ BoardFields::NONCE_FIELD ] = wp_create_nonce( BoardFields::NONCE_ACTION );
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Someone who may not edit boards' ] );
		$this->assertSame( $before, BoardSettings::get( $this->default_board() ) );
	}

	public function test_a_board_remembers_the_first_published_page_it_is_shown_on(): void {
		$draft   = $this->page_with_board( 'draft' );
		$private = $this->page_with_board( 'private' );
		$page    = $this->page_with_board();
		$other   = $this->page_with_board();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->show( $draft );
		$this->show( $private );
		$this->assertSame( 0, BoardSettings::get( $this->default_board() )[ BoardSettings::PAGE ], 'Pages that visitors cannot see do not count.' );

		$this->show( $page );
		$this->show( $other );
		$this->assertSame( get_permalink( $page ), BoardSettings::page_url( $this->default_board() ) );

		wp_trash_post( $page );
		$this->assertSame( '', BoardSettings::page_url( $this->default_board() ), 'A page that is no longer published is not linked to.' );

		$this->show( $other );
		$this->assertSame( get_permalink( $other ), BoardSettings::page_url( $this->default_board() ), 'The board then takes the next page it is shown on.' );
	}

	public function test_a_board_shown_on_a_requests_own_page_does_not_take_that_page(): void {
		$request = $this->create_request( [ 'post_content' => '<!-- wp:' . Board::BLOCK . ' /-->' ] );

		$this->show( $request );

		$this->assertSame( '', BoardSettings::page_url( $this->default_board() ) );
	}

	public function test_requests_link_back_to_their_boards_page(): void {
		$request = $this->create_request( [ 'post_content' => 'Easier on the eyes.' ] );
		$this->assertStringNotContainsString( 'mwtfrs-single__back', $this->show( $request ), 'No page is known yet.' );

		$page = $this->page_with_board();
		$this->show( $page );
		$html = $this->show( $request );

		$this->assertMatchesRegularExpression(
			'#<a href="' . preg_quote( get_permalink( $page ), '#' ) . '">\s*<span aria-hidden="true">&larr;</span>\s*Back to Feature Requests\s*</a>#',
			$html
		);
	}

	/**
	 * Creates a page that shows the default board.
	 *
	 * @param string $status Post status of the page.
	 */
	private function page_with_board( string $status = 'publish' ): int {
		return self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_content' => '<!-- wp:' . Board::BLOCK . ' /-->',
			]
		);
	}

	/**
	 * Opens a post's page and returns its content as a theme prints it.
	 *
	 * @param int $post_id Post ID.
	 */
	private function show( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		if ( ! have_posts() ) {
			return '';
		}
		the_post();

		return (string) apply_filters( 'the_content', get_the_content() );
	}
}
