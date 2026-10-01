<?php
/**
 * Status change tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\StatusQuickEdit;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusChange;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_REST_Request;
use WP_Term;

/**
 * A status change is announced exactly once, whichever screen or code made it.
 */
final class StatusChangeTest extends TestCase {

	/**
	 * Every announced change: request ID, old status slug, new status slug.
	 *
	 * @var array<int, array{0: int, 1: string, 2: string}>
	 */
	private $changes = [];

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->changes = [];
		$_POST         = [];
		$_REQUEST      = [];

		add_action(
			StatusChange::ACTION,
			function ( int $request_id, WP_Term $old, WP_Term $new ): void {
				$this->changes[] = [ $request_id, $old->slug, $new->slug ];
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function test_changing_the_status_in_code_is_announced_once(): void {
		$request = $this->create_request();

		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		$this->assertSame( [ [ $request, 'open', 'planned' ] ], $this->changes );
	}

	public function test_adding_a_status_or_passing_several_is_still_one_change(): void {
		$appended = $this->create_request();
		wp_set_object_terms( $appended, $this->status( 'planned' ), StatusTaxonomy::NAME, true );

		$several = $this->create_request();
		wp_set_object_terms( $several, [ $this->status( 'planned' ), $this->status( 'shipped' ) ], StatusTaxonomy::NAME );

		$this->assertSame(
			[
				[ $appended, 'open', 'planned' ],
				[ $several, 'open', 'shipped' ],
			],
			$this->changes
		);
	}

	public function test_nothing_is_announced_when_the_status_does_not_change(): void {
		$request = $this->create_request();
		$this->assertSame( [], $this->changes, 'A new request getting its first status is not a change.' );

		wp_set_object_terms( $request, $this->status( 'open' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $request, self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] ), BoardTaxonomy::NAME );
		wp_set_object_terms( $request, self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] ), CategoryTaxonomy::NAME );

		$this->assertSame( [], $this->changes );
	}

	public function test_a_request_created_in_a_status_other_than_the_default_did_not_change_status(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = wp_insert_post(
			[
				'post_type'   => RequestPostType::NAME,
				'post_status' => 'publish',
				'post_title'  => 'Already planned',
				'tax_input'   => [ StatusTaxonomy::NAME => [ $this->status( 'planned' ) ] ],
			]
		);

		$this->assertSame( [ $this->status( 'planned' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertSame( [], $this->changes, 'It was never Open, so it did not move from Open to Planned.' );
	}

	public function test_a_change_that_other_code_undoes_is_not_announced(): void {
		$request = $this->create_request();
		$open    = $this->status( 'open' );
		$undoing = false;
		add_action(
			'set_object_terms',
			static function ( int $object_id, $terms, $tt_ids, string $taxonomy ) use ( $open, &$undoing ): void {
				if ( StatusTaxonomy::NAME === $taxonomy && ! $undoing ) {
					$undoing = true;
					wp_set_object_terms( $object_id, $open, $taxonomy );
					$undoing = false;
				}
			},
			15,
			4
		);

		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		$this->assertSame( [ $open ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertSame( [], $this->changes );
	}

	public function test_deleting_a_status_moves_its_requests_without_an_announcement(): void {
		$beta    = self::factory()->term->create( [ 'taxonomy' => StatusTaxonomy::NAME ] );
		$request = $this->create_request();
		wp_set_object_terms( $request, $beta, StatusTaxonomy::NAME );
		$this->changes = [];

		wp_delete_term( $beta, StatusTaxonomy::NAME );

		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertSame( [], $this->changes );
	}

	public function test_the_edit_screen_announces_a_change_once(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = $this->create_request();
		$_POST   = [
			'post_ID'    => (string) $request,
			'post_type'  => RequestPostType::NAME,
			'post_title' => 'Dark mode',
			'content'    => 'Easier on the eyes.',
			'tax_input'  => [
				StatusTaxonomy::NAME => [ (string) $this->status( 'planned' ) ],
				BoardTaxonomy::NAME  => [ (string) $this->default_board() ],
			],
		];

		edit_post();

		$this->assertSame( [ [ $request, 'open', 'planned' ] ], $this->changes );
	}

	public function test_quick_edit_announces_a_change_once(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request  = $this->create_request();
		$_POST    = [
			'post_ID'              => (string) $request,
			'post_type'            => RequestPostType::NAME,
			'post_title'           => 'Dark mode',
			'_status'              => 'publish',
			'_inline_edit'         => wp_create_nonce( 'inlineeditnonce' ),
			StatusQuickEdit::FIELD => (string) $this->status( 'in-progress' ),
		];
		$_REQUEST = $_POST;

		edit_post();

		$this->assertSame( [ [ $request, 'open', 'in-progress' ] ], $this->changes );
	}

	public function test_bulk_edit_announces_one_change_per_request_that_changed(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$open     = $this->create_request();
		$planned  = $this->create_request();
		$shipped  = $this->create_request();
		wp_set_object_terms( $planned, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		$this->changes = [];
		$_REQUEST      = [
			'post'                 => [ (string) $open, (string) $planned, (string) $shipped ],
			'post_type'            => RequestPostType::NAME,
			'bulk_edit'            => 'Update',
			'_wpnonce'             => wp_create_nonce( 'bulk-posts' ),
			'_status'              => '-1',
			StatusQuickEdit::FIELD => (string) $this->status( 'shipped' ),
		];

		bulk_edit_posts( $_REQUEST );

		$this->assertSame(
			[
				[ $open, 'open', 'shipped' ],
				[ $planned, 'planned', 'shipped' ],
			],
			$this->changes
		);
	}

	public function test_the_rest_api_announces_a_change_once(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = $this->create_request();
		$update  = new WP_REST_Request( 'POST', rest_get_route_for_post( $request ) );
		$update->set_param( StatusTaxonomy::NAME, [ $this->status( 'under-review' ) ] );

		$response = rest_do_request( $update );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ [ $request, 'open', 'under-review' ] ], $this->changes );
	}
}
