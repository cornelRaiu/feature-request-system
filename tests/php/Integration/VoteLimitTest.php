<?php
/**
 * Vote limit tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\BoardFields;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A board can limit how many votes each person gives on its requests that are open for
 * voting. Votes on closed requests come back.
 */
final class VoteLimitTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_GET  = [];
		$_POST = [];
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTE_LIMIT => 2 ] );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_GET  = [];
		$_POST = [];
		parent::tear_down();
	}

	public function test_a_vote_over_the_limit_is_refused_with_403(): void {
		wp_set_current_user( self::factory()->user->create() );
		list( $first, $second, $third ) = $this->create_requests( 3 );

		$this->assertSame( [ 'votes' => 1, 'voted' => true, 'votes_left' => 1, 'following' => true ], $this->vote( 'POST', $first )->get_data() );
		$this->assertSame( 0, $this->vote( 'POST', $second )->get_data()['votes_left'] );

		$refused = $this->vote( 'POST', $third );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( VoteService::ERROR_LIMIT_REACHED, $refused->get_data()['code'] );
		$this->assertSame( 2, $refused->get_data()['data']['limit'] );
		$this->assertSame( 0, $this->vote_rows( $third ), 'The vote is not stored.' );
		$this->assertSame( '0', get_post_meta( $third, RequestPostType::META_VOTES, true ) );
	}

	public function test_voting_again_and_taking_a_vote_back_still_work_at_the_limit(): void {
		wp_set_current_user( self::factory()->user->create() );
		list( $first, $second, $third ) = $this->create_requests( 3 );
		$this->vote( 'POST', $first );
		$this->vote( 'POST', $second );

		$this->assertSame( 200, $this->vote( 'POST', $first )->get_status(), 'A vote already given is not a new one.' );

		$retracted = $this->vote( 'DELETE', $first );
		$this->assertSame( [ 'votes' => 0, 'voted' => false, 'votes_left' => 1, 'following' => false ], $retracted->get_data() );
		$this->assertSame( 200, $this->vote( 'POST', $third )->get_status(), 'The vote taken back can be given again.' );
	}

	public function test_a_vote_comes_back_when_its_request_is_closed(): void {
		wp_set_current_user( self::factory()->user->create() );
		list( $first, $second, $third ) = $this->create_requests( 3 );
		$this->vote( 'POST', $first );
		$this->vote( 'POST', $second );

		wp_set_object_terms( $first, $this->status( 'shipped' ), StatusTaxonomy::NAME );

		$response = $this->vote( 'POST', $third );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['votes_left'] );
	}

	public function test_votes_on_requests_that_are_not_open_do_not_count(): void {
		wp_set_current_user( self::factory()->user->create() );
		list( $first, $second, $third ) = $this->create_requests( 3 );
		$this->vote( 'POST', $first );
		$this->vote( 'POST', $second );

		wp_update_post(
			[
				'ID'          => $first,
				'post_status' => 'draft',
			]
		);
		wp_update_post(
			[
				'ID'            => $second,
				'post_password' => 'secret',
			]
		);

		$this->assertSame( 1, $this->vote( 'POST', $third )->get_data()['votes_left'] );
	}

	public function test_only_votes_on_the_same_board_count(): void {
		wp_set_current_user( self::factory()->user->create() );
		$other = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		list( $first, $second ) = $this->create_requests( 2 );
		$elsewhere              = $this->create_request();
		wp_set_object_terms( $elsewhere, $other, BoardTaxonomy::NAME );

		$this->vote( 'POST', $elsewhere );
		$this->vote( 'POST', $first );

		$this->assertSame( 0, $this->vote( 'POST', $second )->get_data()['votes_left'] );
		$this->assertArrayNotHasKey( 'votes_left', $this->vote( 'DELETE', $elsewhere )->get_data(), 'The other board has no limit.' );
	}

	public function test_guests_are_limited_by_their_voter_cookie(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		list( $first, $second, $third ) = $this->create_requests( 3 );
		$origin                         = [ 'Origin' => home_url() ];

		$this->vote( 'POST', $first, $origin );
		$this->vote( 'POST', $second, $origin );
		$this->assertSame( VoteService::ERROR_LIMIT_REACHED, $this->vote( 'POST', $third, $origin )->get_data()['code'] );

		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$this->assertSame( 1, $this->vote( 'POST', $third, $origin )->get_data()['votes_left'], 'Another visitor has votes of their own.' );
	}

	public function test_without_javascript_the_board_says_the_votes_are_used(): void {
		wp_set_current_user( self::factory()->user->create() );
		list( $first, $second, $third ) = $this->create_requests( 3 );
		$this->vote( 'POST', $first );
		$this->vote( 'POST', $second );

		$back = VoteForm::process(
			[
				'request_id'  => (string) $third,
				'redirect_to' => home_url( '/board/' ),
				'_wpnonce'    => wp_create_nonce( VoteForm::ACTION ),
			],
			'',
			''
		);

		$this->assertStringContainsString( 'fr-notice=votes-used', $back );
		$this->assertSame( 0, $this->vote_rows( $third ) );

		wp_parse_str( (string) wp_parse_url( $back, PHP_URL_QUERY ), $_GET );
		$this->assertStringContainsString( 'You have used all your votes on this board.', $this->render() );
	}

	public function test_the_board_says_how_many_votes_a_user_has_left(): void {
		wp_set_current_user( self::factory()->user->create() );
		list( $first ) = $this->create_requests( 2 );
		$this->vote( 'POST', $first );

		$html = $this->render();

		$this->assertStringContainsString( 'Each person can vote for 2 requests on this board.', $html );
		$this->assertMatchesRegularExpression( '/<span data-wp-bind--hidden="!context.allowance" data-wp-text="context.allowance"[^>]*>Votes you have left: 1.<\/span>/', $html );
		$this->assertStringContainsString( '"allowanceBoard":' . $this->default_board(), $html, 'The script asks for this board when the page loads.' );
	}

	public function test_guests_get_no_number_in_the_html_and_ask_for_it_after_loading(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		list( $first ) = $this->create_requests( 1 );

		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'd4', 16 );
		VoteService::cast( $first );
		$html = $this->render();

		$this->assertStringContainsString( 'Each person can vote for 2 requests on this board.', $html, 'The same for everyone.' );
		$this->assertMatchesRegularExpression( '/<span hidden data-wp-bind--hidden="!context.allowance" data-wp-text="context.allowance"><\/span>/', $html );
		$this->assertStringNotContainsString( 'Votes you have left', $html, 'No number in the HTML of a page that may be cached.' );

		$this->assertSame(
			[
				'voted'      => [ $first ],
				'votes_left' => 1,
			],
			$this->state( [ $first ], $this->default_board() )->get_data()
		);
		$this->assertSame( [ 'voted' => [ $first ] ], $this->state( [ $first ] )->get_data(), 'Only when asked.' );

		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$this->assertSame( 2, $this->state( [ $first ], $this->default_board() )->get_data()['votes_left'], 'A new visitor has every vote.' );
	}

	public function test_boards_without_a_limit_say_nothing_about_it(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTE_LIMIT => 0 ] );
		wp_set_current_user( self::factory()->user->create() );
		list( $first ) = $this->create_requests( 1 );

		$html = $this->render();

		$this->assertStringNotContainsString( 'mwtfrs-board__allowance', $html );
		$this->assertStringNotContainsString( 'allowance', $html );
		$this->assertArrayNotHasKey( 'votes_left', $this->vote( 'POST', $first )->get_data() );
		$this->assertArrayNotHasKey( 'votes_left', $this->state( [ $first ], $this->default_board() )->get_data() );
	}

	public function test_the_limit_is_set_and_cleared_on_the_boards_form(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		ob_start();
		do_action( BoardTaxonomy::NAME . '_edit_form_fields', get_term( $this->default_board() ), BoardTaxonomy::NAME );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<label for="mwtfrs-board-vote-limit">Votes each person can give</label>', $html );
		$this->assertStringContainsString( 'name="' . BoardFields::FIELD . '[' . BoardSettings::VOTE_LIMIT . ']" value="2"', $html );

		$_POST = [
			BoardFields::NONCE_FIELD => wp_create_nonce( BoardFields::NONCE_ACTION ),
			BoardFields::FIELD       => [ BoardSettings::VOTE_LIMIT => '5' ],
		];
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Ideas' ] );
		$this->assertSame( 5, BoardSettings::vote_limit( $this->default_board() ) );

		$_POST[ BoardFields::FIELD ] = [ BoardSettings::VOTE_LIMIT => '' ];
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Ideas' ] );
		$this->assertSame( 0, BoardSettings::vote_limit( $this->default_board() ), 'An empty field means no limit.' );

		ob_start();
		do_action( BoardTaxonomy::NAME . '_add_form_fields', BoardTaxonomy::NAME );
		$this->assertStringContainsString( 'name="' . BoardFields::FIELD . '[' . BoardSettings::VOTE_LIMIT . ']" value=""', (string) ob_get_clean() );
	}

	/**
	 * Creates published requests on the default board.
	 *
	 * @param int $count How many.
	 * @return int[]
	 */
	private function create_requests( int $count ): array {
		$ids = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->create_request();
		}

		return $ids;
	}

	/**
	 * Votes or takes a vote back through the REST API.
	 *
	 * @param string                $method     POST or DELETE.
	 * @param int                   $request_id Request ID.
	 * @param array<string, string> $headers    Request headers.
	 */
	private function vote( string $method, int $request_id, array $headers = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestApi::NAMESPACE . '/requests/' . $request_id . '/vote' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Asks the state route about some requests, and a board's votes left.
	 *
	 * @param int[] $ids      Request IDs.
	 * @param int   $board_id Board term ID, or 0 to leave it out.
	 */
	private function state( array $ids, int $board_id = 0 ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/me/state' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $board_id ? [ 'ids' => $ids, 'board' => $board_id ] : [ 'ids' => $ids ] ) );

		return rest_do_request( $request );
	}

	/**
	 * Counts the vote rows stored for a request.
	 *
	 * @param int $request_id Request ID.
	 */
	private function vote_rows( int $request_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE request_id = %d', Schema::table( Schema::VOTES ), $request_id ) );
	}

	/**
	 * Renders the board block of the default board.
	 */
	private function render(): string {
		return render_block(
			[
				'blockName'    => Board::BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}
}
