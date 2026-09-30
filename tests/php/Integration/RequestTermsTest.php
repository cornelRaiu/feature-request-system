<?php
/**
 * Request invariant tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;

/**
 * Every request has a vote count and exactly one board and one status, however it is saved.
 */
final class RequestTermsTest extends TestCase {

	public function test_new_request_starts_with_zero_votes_and_the_default_board_and_status(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );

		$this->assertTrue( metadata_exists( 'post', $request, RequestPostType::META_VOTES ) );
		$this->assertSame( '0', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
		$this->assertSame( [ RequestTerms::default_term( StatusTaxonomy::NAME ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertSame( [ RequestTerms::default_term( BoardTaxonomy::NAME ) ], $this->term_ids( $request, BoardTaxonomy::NAME ) );
	}

	public function test_new_requests_are_included_when_sorting_by_votes(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );

		$sorted = get_posts(
			[
				'post_type' => RequestPostType::NAME,
				'meta_key'  => RequestPostType::META_VOTES,
				'orderby'   => 'meta_value_num',
				'fields'    => 'ids',
			]
		);

		$this->assertContains( $request, $sorted );
	}

	public function test_request_saved_with_a_status_keeps_it(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$planned = $this->status( 'planned' );

		$request = wp_insert_post(
			[
				'post_type'   => RequestPostType::NAME,
				'post_title'  => 'Dark mode',
				'post_status' => 'publish',
				'tax_input'   => [ StatusTaxonomy::NAME => [ $planned ] ],
			]
		);

		$this->assertSame( [ $planned ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertSame( [ RequestTerms::default_term( BoardTaxonomy::NAME ) ], $this->term_ids( $request, BoardTaxonomy::NAME ) );
	}

	public function test_adding_a_status_replaces_the_previous_one(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );

		wp_set_object_terms( $request, $this->status( 'shipped' ), StatusTaxonomy::NAME, true );

		$this->assertSame( [ $this->status( 'shipped' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
	}

	public function test_setting_several_statuses_keeps_the_last_one(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );

		wp_set_object_terms( $request, [ $this->status( 'planned' ), $this->status( 'shipped' ) ], StatusTaxonomy::NAME );

		$this->assertSame( [ $this->status( 'shipped' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
	}

	public function test_adding_a_board_replaces_the_previous_one(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );
		$mobile  = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );

		wp_set_object_terms( $request, $mobile, BoardTaxonomy::NAME, true );

		$this->assertSame( [ $mobile ], $this->term_ids( $request, BoardTaxonomy::NAME ) );
	}

	public function test_deleting_a_status_moves_its_requests_to_the_default_status(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		wp_delete_term( $this->status( 'planned' ), StatusTaxonomy::NAME );

		$this->assertSame( [ RequestTerms::default_term( StatusTaxonomy::NAME ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
	}

	public function test_deleting_a_board_moves_its_requests_to_the_default_board(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );
		$mobile  = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		wp_set_object_terms( $request, $mobile, BoardTaxonomy::NAME );

		wp_delete_term( $mobile, BoardTaxonomy::NAME );

		$this->assertSame( [ RequestTerms::default_term( BoardTaxonomy::NAME ) ], $this->term_ids( $request, BoardTaxonomy::NAME ) );
	}

	public function test_deleting_the_default_status_in_code_promotes_the_oldest_remaining_one(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );

		wp_delete_term( RequestTerms::default_term( StatusTaxonomy::NAME ), StatusTaxonomy::NAME );

		$this->assertSame( $this->status( 'under-review' ), RequestTerms::default_term( StatusTaxonomy::NAME ) );
		$this->assertSame( [ $this->status( 'under-review' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
	}

	public function test_default_board_and_status_cannot_be_deleted(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertFalse( current_user_can( 'delete_term', RequestTerms::default_term( StatusTaxonomy::NAME ) ) );
		$this->assertFalse( current_user_can( 'delete_term', RequestTerms::default_term( BoardTaxonomy::NAME ) ) );
		$this->assertTrue( current_user_can( 'delete_term', $this->status( 'declined' ) ) );
	}

	public function test_updating_a_request_keeps_its_vote_count(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );
		update_post_meta( $request, RequestPostType::META_VOTES, 7 );

		wp_update_post(
			[
				'ID'         => $request,
				'post_title' => 'Renamed',
			]
		);

		$this->assertSame( '7', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	public function test_other_post_types_are_left_alone(): void {
		$post = self::factory()->post->create();

		$this->assertFalse( metadata_exists( 'post', $post, RequestPostType::META_VOTES ) );
		$this->assertSame( [], $this->term_ids( $post, StatusTaxonomy::NAME ) );
	}
}
