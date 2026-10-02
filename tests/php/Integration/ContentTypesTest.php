<?php
/**
 * Content type registration tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\TermSelectBox;
use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_REST_Request;

/**
 * The request post type, its capabilities and its edit screen.
 */
final class ContentTypesTest extends TestCase {

	public function test_capability_list_matches_what_wordpress_derives_for_the_post_type(): void {
		$cap = get_post_type_object( RequestPostType::NAME )->cap;

		$this->assertEqualsCanonicalizing(
			[
				$cap->edit_posts,
				$cap->edit_others_posts,
				$cap->edit_private_posts,
				$cap->edit_published_posts,
				$cap->publish_posts,
				$cap->read_private_posts,
				$cap->delete_posts,
				$cap->delete_others_posts,
				$cap->delete_private_posts,
				$cap->delete_published_posts,
			],
			Capabilities::REQUEST_CAPS
		);
	}

	public function test_editors_can_edit_requests_and_authors_cannot(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );

		$this->assertTrue( user_can( $editor, 'edit_post', $request ) );
		$this->assertFalse( user_can( $author, 'edit_post', $request ) );
	}

	/**
	 * WordPress lets whoever may assign the terms of a taxonomy without parents also add
	 * terms to it over the REST API. For boards and statuses that is not enough.
	 */
	public function test_adding_a_board_or_a_status_over_the_rest_api_takes_the_capability_to_manage_them(): void {
		add_role(
			'request_writer',
			'Request writer',
			[
				'read' => true,
				'edit_' . RequestPostType::CAPABILITY_TYPE => true,
			]
		);
		$taxonomies = [ BoardTaxonomy::NAME, StatusTaxonomy::NAME, CategoryTaxonomy::NAME ];
		$add        = static function ( string $taxonomy ): int {
			$request = new WP_REST_Request( 'POST', '/wp/v2/' . $taxonomy );
			$request->set_body_params( [ 'name' => 'Made up' ] );

			return rest_do_request( $request )->get_status();
		};

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'request_writer' ] ) );
		$this->assertTrue( current_user_can( get_taxonomy( BoardTaxonomy::NAME )->cap->assign_terms ), 'This user may choose a board for their request.' );
		foreach ( $taxonomies as $taxonomy ) {
			$this->assertSame( 403, $add( $taxonomy ), $taxonomy );
			$this->assertNull( term_exists( 'Made up', $taxonomy ), $taxonomy );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		foreach ( $taxonomies as $taxonomy ) {
			$this->assertSame( 201, $add( $taxonomy ), $taxonomy );
		}

		remove_role( 'request_writer' );
	}

	public function test_only_administrators_manage_settings(): void {
		$this->assertTrue( user_can( self::factory()->user->create( [ 'role' => 'administrator' ] ), Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( user_can( self::factory()->user->create( [ 'role' => 'editor' ] ), Capabilities::MANAGE_SETTINGS ) );
	}

	public function test_published_requests_have_their_own_url(): void {
		// Post types add their rewrite rules at registration, so register again with pretty permalinks on.
		$this->set_permalink_structure( '/%postname%/' );
		RequestPostType::register();

		$request = self::factory()->post->create(
			[
				'post_type' => RequestPostType::NAME,
				'post_name' => 'dark-mode',
			]
		);

		$this->assertSame( home_url( '/' . RequestPostType::REWRITE_SLUG . '/dark-mode/' ), get_permalink( $request ) );
	}

	public function test_requests_are_edited_in_the_classic_editor(): void {
		$this->assertFalse( use_block_editor_for_post_type( RequestPostType::NAME ) );
		$this->assertTrue( use_block_editor_for_post_type( 'post' ) );
	}

	public function test_status_box_is_a_single_choice_with_the_current_status_selected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		ob_start();
		TermSelectBox::render( get_post( $request ), [ 'args' => [ 'taxonomy' => StatusTaxonomy::NAME ] ] );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="tax_input[' . StatusTaxonomy::NAME . '][]"', $html );
		$this->assertStringNotContainsString( 'multiple', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertSame( 6, substr_count( $html, '<option' ) );
		$this->assertMatchesRegularExpression( '/<option value="' . $this->status( 'planned' ) . '"\s+selected=\'selected\'>Planned<\/option>/', $html );
	}

	public function test_status_box_is_read_only_without_permission_to_assign_statuses(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );

		ob_start();
		TermSelectBox::render( get_post( $request ), [ 'args' => [ 'taxonomy' => StatusTaxonomy::NAME ] ] );
		$html = ob_get_clean();

		$this->assertStringContainsString( "disabled='disabled'", $html );
	}
}
