<?php
/**
 * Request details box tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\RequestDetailsBox;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Voting\VoteRepository;

/**
 * The box on a request's edit screen that shows who voted for it and who submitted it.
 */
final class RequestDetailsBoxTest extends TestCase {

	public function test_edit_screen_has_a_box_for_the_details_the_board_and_the_status(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		do_action( 'add_meta_boxes_' . RequestPostType::NAME, get_post( $this->create_request() ) );
		$boxes = $GLOBALS['wp_meta_boxes'][ RequestPostType::NAME ]['side'];
		unset( $GLOBALS['wp_meta_boxes'][ RequestPostType::NAME ] );

		$this->assertSame( [ 'Request details' ], array_values( wp_list_pluck( $boxes['high'], 'title' ) ) );
		$this->assertSame( [ 'Board', 'Request status' ], array_values( wp_list_pluck( $boxes['core'], 'title' ) ), 'One choice each, so the titles are singular.' );
	}

	public function test_box_names_the_users_who_voted_and_counts_the_guests(): void {
		$request = $this->create_request();
		$ana     = self::factory()->user->create( [ 'display_name' => 'Ana Voter' ] );
		$ben     = self::factory()->user->create( [ 'display_name' => 'Ben Voter' ] );
		$this->vote( $request, $ana );
		$this->vote( $request, $ben );
		$this->vote( $request, 0 );
		$this->vote( $request, 0 );
		$this->vote( $request, 0 );
		$this->log_in_as_user_manager();

		$html = $this->box( $request );

		$this->assertStringContainsString( '<strong>5 votes</strong>', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_edit_user_link( $ana ) ) . '">Ana Voter</a>', $html );
		$this->assertStringContainsString( '>Ben Voter</a>', $html );
		$this->assertStringContainsString( '<p>3 guests</p>', $html );
	}

	public function test_long_lists_of_voters_are_cut_short(): void {
		$request = $this->create_request();
		foreach ( self::factory()->user->create_many( 22 ) as $user ) {
			$this->vote( $request, $user );
		}

		$html = $this->box( $request );

		$this->assertSame( 20, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( '<p>2 more users</p>', $html );
	}

	public function test_box_counts_the_followers(): void {
		$request = $this->create_request();
		$this->create_followers( $request, 1 );
		$this->assertStringContainsString( '<p>1 follower</p>', $this->box( $request ) );

		$this->create_followers( $request, 2 );
		$this->assertStringContainsString( '<p>3 followers</p>', $this->box( $request ) );
	}

	public function test_requests_without_votes_say_so(): void {
		$html = $this->box( $this->create_request() );

		$this->assertStringContainsString( '<strong>0 votes</strong>', $html );
		$this->assertStringNotContainsString( '<ul>', $html );
		$this->assertStringNotContainsString( 'follower', $html );
		$this->assertStringNotContainsString( 'Submitted by', $html, 'A request written in the admin has no submitter.' );
	}

	public function test_box_shows_the_guest_who_submitted_the_request(): void {
		$request = $this->create_request();
		update_post_meta( $request, RequestPostType::META_SUBMITTER_NAME, 'Robin <Guest>' );
		update_post_meta( $request, RequestPostType::META_SUBMITTER_EMAIL, 'robin@example.com' );

		$this->assertStringContainsString(
			'<p>Submitted by Robin &lt;Guest&gt;, <a href="mailto:robin@example.com">robin@example.com</a></p>',
			$this->box( $request )
		);
	}

	public function test_box_shows_the_user_who_submitted_the_request_and_a_spam_flag(): void {
		$request = $this->create_request();
		update_post_meta( $request, RequestPostType::META_SUBMITTER_ID, self::factory()->user->create( [ 'display_name' => 'Sam Subscriber' ] ) );
		update_post_meta( $request, RequestPostType::META_FLAGGED_AS_SPAM, 1 );

		$html = $this->box( $request );

		$this->assertStringContainsString( '<p>Submitted by Sam Subscriber</p>', $html, 'A plain name for someone who may not edit users.' );
		$this->assertStringContainsString( 'A spam check flagged this request', $html );
	}

	/**
	 * Records a vote for a request.
	 *
	 * @param int $request_id Request ID.
	 * @param int $user_id    User ID, or 0 for a guest.
	 */
	private function vote( int $request_id, int $user_id ): void {
		VoteRepository::add( $request_id, hash( 'sha256', uniqid( 'voter', true ) ), $user_id );
	}

	/**
	 * Returns the box of a request.
	 *
	 * @param int $request_id Request ID.
	 */
	private function box( int $request_id ): string {
		ob_start();
		RequestDetailsBox::render( get_post( $request_id ) );

		return (string) ob_get_clean();
	}
}
