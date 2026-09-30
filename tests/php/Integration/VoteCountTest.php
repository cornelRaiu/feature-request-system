<?php
/**
 * Stored vote count tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteService;

/**
 * The recount behind `wp mwtfrs recount`.
 */
final class VoteCountTest extends TestCase {

	public function test_recount_fixes_wrong_and_missing_counts_and_leaves_correct_ones(): void {
		$correct = $this->create_request();
		$wrong   = $this->create_request();
		$missing = $this->create_request();
		foreach ( range( 1, 2 ) as $voter ) {
			wp_set_current_user( self::factory()->user->create() );
			VoteService::cast( $correct );
		}
		update_post_meta( $wrong, RequestPostType::META_VOTES, 5 );
		delete_post_meta( $missing, RequestPostType::META_VOTES );

		$result = VoteCount::sync_all();

		$this->assertSame(
			[
				'checked' => 3,
				'fixed'   => 2,
			],
			$result
		);
		$this->assertSame( '2', get_post_meta( $correct, RequestPostType::META_VOTES, true ) );
		$this->assertSame( '0', get_post_meta( $wrong, RequestPostType::META_VOTES, true ) );
		$this->assertTrue( metadata_exists( 'post', $missing, RequestPostType::META_VOTES ) );
	}

	public function test_recount_covers_requests_in_every_status(): void {
		$draft = $this->create_request( [ 'post_status' => 'draft' ] );
		delete_post_meta( $draft, RequestPostType::META_VOTES );

		$this->assertSame( 1, VoteCount::sync_all()['fixed'] );
		$this->assertTrue( metadata_exists( 'post', $draft, RequestPostType::META_VOTES ) );
	}
}
