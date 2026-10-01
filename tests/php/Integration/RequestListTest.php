<?php
/**
 * Admin requests list tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\RequestList;
use FeatureRequestSystem\Admin\StatusQuickEdit;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_Query;

/**
 * The requests list in the admin: its columns, sorting, filters, Quick Edit and Bulk Edit.
 */
final class RequestListTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
	}

	public function tear_down(): void {
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function test_votes_status_board_and_categories_follow_the_title(): void {
		$columns = apply_filters(
			'manage_' . RequestPostType::NAME . '_posts_columns',
			[
				'cb'                                => '<input type="checkbox" />',
				'title'                             => 'Title',
				RequestList::taxonomy_column( BoardTaxonomy::NAME ) => 'Boards',
				RequestList::taxonomy_column( StatusTaxonomy::NAME ) => 'Statuses',
				RequestList::taxonomy_column( CategoryTaxonomy::NAME ) => 'Categories',
				'comments'                          => 'Comments',
				'date'                              => 'Date',
			]
		);

		$this->assertSame(
			[
				'cb'                           => '<input type="checkbox" />',
				'title'                        => 'Title',
				RequestList::COLUMN_VOTES      => 'Votes',
				RequestList::taxonomy_column( StatusTaxonomy::NAME ) => 'Status',
				RequestList::taxonomy_column( BoardTaxonomy::NAME ) => 'Board',
				RequestList::taxonomy_column( CategoryTaxonomy::NAME ) => 'Categories',
				'comments'                     => 'Comments',
				'date'                         => 'Date',
			],
			$columns
		);
	}

	public function test_votes_column_shows_the_count(): void {
		$request = $this->request_with_votes( 1234 );

		ob_start();
		do_action( 'manage_' . RequestPostType::NAME . '_posts_custom_column', RequestList::COLUMN_VOTES, $request );

		$this->assertSame( '1,234', ob_get_clean() );
	}

	public function test_list_can_be_sorted_by_votes_in_both_directions(): void {
		$few  = $this->request_with_votes( 1 );
		$most = $this->request_with_votes( 9 );
		$some = $this->request_with_votes( 4 );

		$sortable = apply_filters( 'manage_edit-' . RequestPostType::NAME . '_sortable_columns', [] );
		$this->assertSame( [ RequestList::COLUMN_VOTES, true ], $sortable[ RequestList::COLUMN_VOTES ], 'Most votes first on the first click.' );

		$this->assertSame( [ $most, $some, $few ], $this->list_sorted_by_votes( 'desc' ) );
		$this->assertSame( [ $few, $some, $most ], $this->list_sorted_by_votes( 'asc' ) );
	}

	public function test_status_column_shows_each_status_with_its_colour(): void {
		$planned = get_term( $this->status( 'planned' ) );

		$links = apply_filters( 'post_column_taxonomy_links', [ '<a href="#">Planned</a>' ], StatusTaxonomy::NAME, [ $planned ] );

		$this->assertStringContainsString( 'background:#6d4fc2', $links[0] );
		$this->assertStringEndsWith( '<a href="#">Planned</a>', $links[0] );
		$this->assertSame( [ '<a href="#">General</a>' ], apply_filters( 'post_column_taxonomy_links', [ '<a href="#">General</a>' ], BoardTaxonomy::NAME, [ get_term( $this->default_board() ) ] ) );
	}

	public function test_list_can_be_filtered_by_status_board_and_category(): void {
		$this->assertStringNotContainsString( 'name="' . BoardTaxonomy::NAME . '"', $this->filters(), 'One board leaves nothing to choose.' );
		$this->assertStringNotContainsString( 'name="' . CategoryTaxonomy::NAME . '"', $this->filters(), 'Nor do no categories.' );

		self::factory()->term->create(
			[
				'taxonomy' => BoardTaxonomy::NAME,
				'name'     => 'Mobile app',
				'slug'     => 'mobile-app',
			]
		);
		self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Reports',
			]
		);
		$_GET[ StatusTaxonomy::NAME ] = 'planned';

		$html = $this->filters();

		$this->assertStringContainsString( '<select name="' . StatusTaxonomy::NAME . '"', $html );
		$this->assertStringContainsString( '<option value="planned" selected=\'selected\'>Planned</option>', $html );
		$this->assertLessThan( strpos( $html, '>Shipped<' ), strpos( $html, '>Under Review<' ), 'Statuses keep their workflow order.' );
		$this->assertStringContainsString( '<option value="mobile-app">Mobile app</option>', $html );
		$this->assertStringContainsString( '<select name="' . CategoryTaxonomy::NAME . '"', $html );

		ob_start();
		do_action( 'restrict_manage_posts', 'post', 'top' );
		$this->assertSame( '', ob_get_clean(), 'Other post types get no filters.' );
	}

	public function test_quick_edit_and_bulk_edit_offer_a_status_dropdown_to_moderators(): void {
		$column = RequestList::taxonomy_column( StatusTaxonomy::NAME );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$quick = $this->edit_box( 'quick_edit_custom_box', $column );
		$this->assertStringContainsString( '<select name="' . StatusQuickEdit::FIELD . '"><option value="' . $this->status( 'open' ) . '">Open</option>', $quick );

		$bulk = $this->edit_box( 'bulk_edit_custom_box', $column );
		$this->assertStringContainsString( '<select name="' . StatusQuickEdit::FIELD . '"><option value="">— No Change —</option>', $bulk );

		$this->assertSame( '', $this->edit_box( 'quick_edit_custom_box', RequestList::COLUMN_VOTES ), 'Once, not once per column.' );
		$this->assertSame( '', $this->edit_box( 'quick_edit_custom_box', $column, 'post' ), 'Not for other post types.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( '', $this->edit_box( 'quick_edit_custom_box', $column ) );
	}

	public function test_each_row_carries_its_current_status_for_quick_edit(): void {
		$request = $this->create_request();
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		ob_start();
		do_action( 'add_inline_data', get_post( $request ), get_post_type_object( RequestPostType::NAME ) );

		$this->assertSame( '<div class="' . StatusQuickEdit::FIELD . '">' . $this->status( 'planned' ) . '</div>', ob_get_clean() );
	}

	public function test_quick_edit_sets_the_chosen_status(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = $this->create_request();

		$this->quick_edit( $request, $this->status( 'planned' ), wp_create_nonce( 'inlineeditnonce' ) );

		$this->assertSame( [ $this->status( 'planned' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
	}

	public function test_the_status_field_is_ignored_outside_a_genuine_quick_or_bulk_edit(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = $this->create_request();

		$this->quick_edit( $request, $this->status( 'planned' ), 'forged' );
		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ), 'Without the nonce of Quick Edit.' );

		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );
		$this->quick_edit( $request, 999999, wp_create_nonce( 'inlineeditnonce' ) );
		$this->assertSame( [ $this->status( 'planned' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ), 'A status that does not exist leaves the request where it was.' );
	}

	public function test_bulk_edit_leaves_the_status_alone_unless_one_is_chosen(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request  = $this->create_request();
		$_REQUEST = [
			'post'                 => [ (string) $request ],
			'post_type'            => RequestPostType::NAME,
			'bulk_edit'            => 'Update',
			'_wpnonce'             => wp_create_nonce( 'bulk-posts' ),
			'_status'              => '-1',
			StatusQuickEdit::FIELD => '',
		];

		bulk_edit_posts( $_REQUEST );

		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
	}

	public function test_menu_shows_moderators_how_many_requests_wait_for_approval(): void {
		$menu = [
			26 => [ 'Feature Requests', 'edit_posts', 'edit.php?post_type=' . RequestPostType::NAME, '', 'menu-top', 'menu-posts', 'dashicons-lightbulb' ],
			20 => [ 'Pages', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top', 'menu-pages', 'dashicons-admin-page' ],
		];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( $menu, apply_filters( 'add_menu_classes', $menu ), 'Nothing is waiting.' );

		$this->create_request( [ 'post_status' => 'pending' ] );
		$this->create_request( [ 'post_status' => 'pending' ] );
		$with_badge = apply_filters( 'add_menu_classes', $menu );

		$this->assertStringContainsString( 'Feature Requests <span class="awaiting-mod count-2">', $with_badge[26][0] );
		$this->assertStringContainsString( '<span class="pending-count" aria-hidden="true">2</span>', $with_badge[26][0] );
		$this->assertStringContainsString( '2 requests waiting for approval', $with_badge[26][0] );
		$this->assertSame( 'Pages', $with_badge[20][0] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( $menu, apply_filters( 'add_menu_classes', $menu ), 'Only moderators see the count.' );
	}

	/**
	 * Creates a published request with a stored vote count.
	 *
	 * @param int $votes Vote count.
	 */
	private function request_with_votes( int $votes ): int {
		$request = $this->create_request();
		update_post_meta( $request, RequestPostType::META_VOTES, $votes );

		return $request;
	}

	/**
	 * Runs the admin list's query sorted by the Votes column and returns the request IDs.
	 *
	 * @param string $order Sort direction: asc or desc.
	 * @return int[]
	 */
	private function list_sorted_by_votes( string $order ): array {
		set_current_screen( 'edit-' . RequestPostType::NAME );

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->query(
			[
				'post_type' => RequestPostType::NAME,
				'orderby'   => RequestList::COLUMN_VOTES,
				'order'     => $order,
			]
		);
		set_current_screen( 'front' );

		return wp_list_pluck( $query->posts, 'ID' );
	}

	/**
	 * Returns the filters printed above the requests list.
	 */
	private function filters(): string {
		ob_start();
		do_action( 'restrict_manage_posts', RequestPostType::NAME, 'top' );

		return (string) ob_get_clean();
	}

	/**
	 * Returns what the plugin prints in Quick Edit or Bulk Edit for a column.
	 *
	 * @param string $hook      quick_edit_custom_box or bulk_edit_custom_box.
	 * @param string $column    Column key.
	 * @param string $post_type Post type of the list.
	 */
	private function edit_box( string $hook, string $column, string $post_type = RequestPostType::NAME ): string {
		ob_start();
		do_action( $hook, $column, $post_type, '' );

		return (string) ob_get_clean();
	}

	/**
	 * Saves a request from Quick Edit, the way admin-ajax.php does after checking the nonce.
	 *
	 * @param int    $request_id Request ID.
	 * @param int    $status     Status term ID chosen in the form.
	 * @param string $nonce      Nonce the form sends.
	 */
	private function quick_edit( int $request_id, int $status, string $nonce ): void {
		$_POST    = [
			'post_ID'              => (string) $request_id,
			'post_type'            => RequestPostType::NAME,
			'post_title'           => 'Dark mode',
			'_status'              => 'publish',
			'_inline_edit'         => $nonce,
			StatusQuickEdit::FIELD => (string) $status,
		];
		$_REQUEST = $_POST;

		edit_post();
	}
}
