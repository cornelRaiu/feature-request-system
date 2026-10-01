<?php
/**
 * Similar request suggestion tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Rest\RestApi;
use WP_REST_Request;

/**
 * GET /mwtfrs/v1/boards/{board}/similar?q= suggests existing requests while someone types a title.
 */
final class SimilarRequestsTest extends TestCase {

	public function test_titles_sharing_more_words_come_first(): void {
		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->create_request( [ 'post_title' => 'Dark mode for the mobile app' ] );
		$this->create_request( [ 'post_title' => 'Export to CSV' ] );

		$this->assertSame(
			[ 'Dark mode for the mobile app', 'Dark mode' ],
			$this->titles( 'Add dark mode to the mobile app' ),
			'Not every word has to match, unlike a WordPress search.'
		);
	}

	public function test_equal_matches_are_ordered_by_votes(): void {
		$this->request_with_votes( 'Dark mode in emails', 2 );
		$this->request_with_votes( 'Dark mode in reports', 9 );

		$this->assertSame( [ 'Dark mode in reports', 'Dark mode in emails' ], $this->titles( 'dark mode' ) );
	}

	public function test_only_published_requests_on_the_same_board_are_suggested(): void {
		$other_board = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->create_request(
			[
				'post_title'  => 'Dark mode pending',
				'post_status' => 'pending',
			]
		);
		$this->create_request(
			[
				'post_title'    => 'Dark mode secret',
				'post_password' => 'secret',
			]
		);
		wp_set_object_terms( $this->create_request( [ 'post_title' => 'Dark mode elsewhere' ] ), $other_board, BoardTaxonomy::NAME );

		$this->assertSame( [ 'Dark mode' ], $this->titles( 'dark mode' ) );
	}

	public function test_short_text_and_common_words_suggest_nothing(): void {
		$this->create_request( [ 'post_title' => 'Add the option for more' ] );

		$this->assertSame( [], $this->titles( 'ad' ) );
		$this->assertSame( [], $this->titles( 'Add the option for more' ) );
	}

	public function test_suggestions_show_votes_and_status_as_plain_text(): void {
		$request = $this->request_with_votes( 'Q&A section', 3 );
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		$this->assertSame(
			[
				[
					'id'    => $request,
					'title' => 'Q&A section',
					'url'   => get_permalink( $request ),
					'label' => '3 votes · Planned',
				],
			],
			$this->similar( 'section' )
		);
	}

	public function test_anyone_can_ask_because_only_public_requests_are_returned(): void {
		$this->create_request( [ 'post_title' => 'Dark mode' ] );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( [ 'Dark mode' ], $this->titles( 'dark' ) );
	}

	/**
	 * Asks the REST API for similar requests on the default board.
	 *
	 * @param string $text What the visitor typed.
	 * @return array<int, array<string, mixed>>
	 */
	private function similar( string $text ): array {
		$request = new WP_REST_Request( 'GET', '/' . RestApi::NAMESPACE . '/boards/' . $this->default_board() . '/similar' );
		$request->set_query_params( [ 'q' => $text ] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Titles of the similar requests, in order.
	 *
	 * @param string $text What the visitor typed.
	 * @return string[]
	 */
	private function titles( string $text ): array {
		return wp_list_pluck( $this->similar( $text ), 'title' );
	}

	/**
	 * Creates a published request with a stored vote count.
	 *
	 * @param string $title Request title.
	 * @param int    $votes Vote count.
	 */
	private function request_with_votes( string $title, int $votes ): int {
		$request = $this->create_request( [ 'post_title' => $title ] );
		update_post_meta( $request, RequestPostType::META_VOTES, $votes );

		return $request;
	}
}
