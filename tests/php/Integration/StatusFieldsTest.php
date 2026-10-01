<?php
/**
 * Status settings screen tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\StatusFields;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;

/**
 * A status's colour, position and voting switch on the Statuses screens.
 */
final class StatusFieldsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_POST = [];
		$_GET  = [];
	}

	public function tear_down(): void {
		$_POST = [];
		$_GET  = [];
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_a_new_status_gets_a_colour_and_goes_last(): void {
		$status = self::factory()->term->create( [ 'taxonomy' => StatusTaxonomy::NAME ] );

		$this->assertSame( StatusTaxonomy::DEFAULT_COLOR, get_term_meta( $status, StatusTaxonomy::META_COLOR, true ) );
		$this->assertSame( '70', get_term_meta( $status, StatusTaxonomy::META_ORDER, true ), 'After the six statuses the plugin starts with.' );
		$this->assertFalse( StatusTaxonomy::is_closed( get_term( $status ) ) );
	}

	public function test_add_form_sets_colour_position_and_voting(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$_POST = $this->form(
			[
				'color'  => '#ff8800',
				'order'  => '25',
				'closed' => '1',
			]
		);

		$status = (int) wp_insert_term( 'On hold', StatusTaxonomy::NAME )['term_id'];

		$this->assertSame( '#ff8800', get_term_meta( $status, StatusTaxonomy::META_COLOR, true ) );
		$this->assertSame( '25', get_term_meta( $status, StatusTaxonomy::META_ORDER, true ) );
		$this->assertTrue( StatusTaxonomy::is_closed( get_term( $status ) ) );
	}

	public function test_edit_form_changes_a_status_and_an_unticked_box_opens_voting(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$shipped = $this->status( 'shipped' );
		$_POST   = $this->form(
			[
				'color' => 'not a colour',
				'order' => '',
			]
		);

		wp_update_term( $shipped, StatusTaxonomy::NAME, [ 'name' => 'Released' ] );

		$this->assertFalse( StatusTaxonomy::is_closed( get_term( $shipped ) ) );
		$this->assertSame( '#1f8a55', get_term_meta( $shipped, StatusTaxonomy::META_COLOR, true ), 'An invalid colour keeps the current one.' );
		$this->assertSame( '50', get_term_meta( $shipped, StatusTaxonomy::META_ORDER, true ), 'An empty position keeps the current one.' );
	}

	public function test_statuses_changed_in_other_ways_keep_their_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$shipped = $this->status( 'shipped' );

		wp_update_term( $shipped, StatusTaxonomy::NAME, [ 'name' => 'Renamed by code' ] );
		$this->assertTrue( StatusTaxonomy::is_closed( get_term( $shipped ) ), 'An update without the form, as from the REST API or WP-CLI.' );

		$_POST                              = $this->form( [ 'color' => '#000000' ] );
		$_POST[ StatusFields::NONCE_FIELD ] = 'forged';
		wp_update_term( $shipped, StatusTaxonomy::NAME, [ 'name' => 'Forged form' ] );
		$this->assertTrue( StatusTaxonomy::is_closed( get_term( $shipped ) ) );
		$this->assertSame( '#1f8a55', get_term_meta( $shipped, StatusTaxonomy::META_COLOR, true ) );
	}

	public function test_forms_show_the_fields_with_their_values(): void {
		ob_start();
		do_action( StatusTaxonomy::NAME . '_add_form_fields', StatusTaxonomy::NAME );
		$add = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="color" id="mwtfrs-status-color" name="' . StatusFields::FIELD . '[color]" value="' . StatusTaxonomy::DEFAULT_COLOR . '">', $add );
		$this->assertStringContainsString( 'name="' . StatusFields::FIELD . '[order]" value="">', $add );
		$this->assertStringContainsString( 'name="' . StatusFields::NONCE_FIELD . '"', $add );

		ob_start();
		do_action( StatusTaxonomy::NAME . '_edit_form_fields', get_term( $this->status( 'declined' ) ), StatusTaxonomy::NAME );
		$edit = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . StatusFields::FIELD . '[color]" value="#b4413c">', $edit );
		$this->assertStringContainsString( 'name="' . StatusFields::FIELD . '[order]" value="60">', $edit );
		$this->assertStringContainsString( 'name="' . StatusFields::FIELD . '[closed]" value="1" checked=\'checked\'> Closed for voting', $edit );
	}

	public function test_statuses_list_shows_colour_and_voting_columns(): void {
		$columns = apply_filters(
			'manage_edit-' . StatusTaxonomy::NAME . '_columns',
			[
				'cb'    => '',
				'name'  => 'Name',
				'slug'  => 'Slug',
				'posts' => 'Count',
			]
		);
		$this->assertSame( [ 'cb', 'name', StatusFields::COLUMN_COLOR, StatusFields::COLUMN_VOTING, 'slug', 'posts' ], array_keys( $columns ) );

		$planned = $this->status( 'planned' );
		$this->assertStringContainsString( 'background:#6d4fc2', apply_filters( 'manage_' . StatusTaxonomy::NAME . '_custom_column', '', StatusFields::COLUMN_COLOR, $planned ) );
		$this->assertSame( 'Open', apply_filters( 'manage_' . StatusTaxonomy::NAME . '_custom_column', '', StatusFields::COLUMN_VOTING, $planned ) );
		$this->assertSame( 'Closed', apply_filters( 'manage_' . StatusTaxonomy::NAME . '_custom_column', '', StatusFields::COLUMN_VOTING, $this->status( 'shipped' ) ) );
	}

	public function test_statuses_screen_lists_statuses_in_workflow_order(): void {
		$alphabetical = [ 'Declined', 'In Progress', 'Open', 'Planned', 'Shipped', 'Under Review' ];
		$workflow     = [ 'Open', 'Under Review', 'Planned', 'In Progress', 'Shipped', 'Declined' ];
		set_current_screen( 'edit-' . RequestPostType::NAME );
		$this->assertSame( $alphabetical, $this->status_names(), 'On other screens WordPress lists terms by name.' );

		set_current_screen( 'edit-' . StatusTaxonomy::NAME );
		$this->assertSame( $workflow, $this->status_names() );

		$_GET['orderby'] = 'name';
		$this->assertSame( $alphabetical, $this->status_names(), 'A column the user sorted wins.' );
	}

	/**
	 * Fields the status form posts.
	 *
	 * @param array<string, string> $fields Values of the plugin's fields.
	 * @return array<string, mixed>
	 */
	private function form( array $fields ): array {
		return [
			StatusFields::NONCE_FIELD => wp_create_nonce( StatusFields::NONCE_ACTION ),
			StatusFields::FIELD       => $fields,
		];
	}

	/**
	 * Names of all statuses, in the order WordPress returns them.
	 *
	 * @return string[]
	 */
	private function status_names(): array {
		return array_values(
			get_terms(
				[
					'taxonomy'   => StatusTaxonomy::NAME,
					'hide_empty' => false,
					'fields'     => 'names',
				]
			)
		);
	}
}
