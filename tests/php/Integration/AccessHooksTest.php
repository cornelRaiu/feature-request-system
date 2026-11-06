<?php
/**
 * Access hook tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\RequestCard;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The mwtfrs_can_vote and mwtfrs_can_submit filters decide who may vote and suggest, as a
 * Pro plugin or a site's own code would use them: they can refuse what a board allows and
 * allow what it refuses, and the page shows what they decided.
 */
final class AccessHooksTest extends TestCase {

	private const PAGE_URL = 'http://example.org/ideas/';

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_GET = [];
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_GET = [];
		parent::tear_down();
	}

	public function test_the_vote_filter_gets_the_boards_answer_the_request_and_the_user(): void {
		$user    = self::factory()->user->create();
		$request = $this->create_request();
		$seen    = [];
		add_filter(
			VoteService::FILTER_CAN_VOTE,
			static function ( bool $allowed, int $request_id, int $user_id ) use ( &$seen ): bool {
				$seen[] = [ $allowed, $request_id, $user_id ];
				return $allowed;
			},
			10,
			3
		);

		wp_set_current_user( $user );
		$this->vote( $request );
		wp_set_current_user( 0 );
		$this->vote( $request, [ 'Origin' => home_url() ] );

		$this->assertContains( [ true, $request, $user ], $seen );
		$this->assertContains( [ false, $request, 0 ], $seen, 'Guests may not vote on the default board.' );
	}

	public function test_the_vote_filter_can_refuse_what_the_board_allows(): void {
		$user    = self::factory()->user->create();
		$request = $this->create_request();
		$this->only_vote_as( 0 );
		wp_set_current_user( $user );

		$response = $this->vote( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( VoteService::ERROR_NOT_ALLOWED, $response->get_data()['code'] );
		$this->assertSame( '0', get_post_meta( $request, RequestPostType::META_VOTES, true ) );

		$back = VoteForm::process( $this->vote_input( $request ), '', '' );
		$this->assertStringContainsString( 'fr-notice=refused', $back, 'The same without JavaScript.' );
		$this->assertSame( '0', get_post_meta( $request, RequestPostType::META_VOTES, true ) );

		wp_parse_str( (string) wp_parse_url( $back, PHP_URL_QUERY ), $_GET );
		$html = $this->render( Board::BLOCK );
		$this->assertStringNotContainsString( 'name="request_id"', $html, 'The board shows the count without a button.' );
		$this->assertStringContainsString( 'mwtfrs-vote--closed', $html );
		$this->assertStringNotContainsString( 'name="request_id"', $this->render( RequestCard::BLOCK, [ 'requestId' => $request ] ), 'And so does a card.' );
	}

	public function test_the_vote_filter_can_allow_what_the_board_refuses(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::NOBODY ] );
		$request = $this->create_request();
		$this->assertStringNotContainsString( 'name="request_id"', $this->render( Board::BLOCK ), 'Voting is off on the board.' );

		$user = self::factory()->user->create();
		$this->only_vote_as( $user );
		wp_set_current_user( $user );

		$this->assertStringContainsString( 'name="request_id" value="' . $request . '"', $this->render( Board::BLOCK ) );
		$this->assertSame( 1, $this->vote( $request )->get_data()['votes'] );
		$this->assertStringContainsString( 'fr-notice=removed', VoteForm::process( $this->vote_input( $request ), '', '' ), 'Without JavaScript too.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( VoteService::ERROR_DISABLED, $this->vote( $request )->get_data()['code'], 'Others still meet the board’s rule.' );
	}

	public function test_the_vote_filter_can_let_guests_vote_where_the_board_wants_users(): void {
		$request = $this->create_request();
		add_filter( VoteService::FILTER_CAN_VOTE, '__return_true' );

		$html = $this->render( Board::BLOCK );
		$this->assertStringContainsString( '"guestVotes":true', $html, 'The page asks for a guest’s votes.' );

		$response = $this->vote( $request, [ 'Origin' => home_url() ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $_COOKIE[ VoterIdentity::COOKIE ] ?? '' );
	}

	public function test_the_vote_filter_cannot_open_a_closed_request(): void {
		$request = $this->create_request();
		wp_set_object_terms( $request, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		add_filter( VoteService::FILTER_CAN_VOTE, '__return_true' );
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( VoteService::ERROR_CLOSED, $this->vote( $request )->get_data()['code'] );
		$this->assertSame( VoteService::ERROR_NOT_FOUND, $this->vote( $this->create_request( [ 'post_status' => 'draft' ] ) )->get_data()['code'] );
	}

	public function test_the_submit_filter_gets_the_boards_answer_the_board_and_the_user(): void {
		$user = self::factory()->user->create();
		$seen = [];
		add_filter(
			SubmissionService::FILTER_CAN_SUBMIT,
			static function ( bool $allowed, int $board_id, int $user_id ) use ( &$seen ): bool {
				$seen[] = [ $allowed, $board_id, $user_id ];
				return $allowed;
			},
			10,
			3
		);

		wp_set_current_user( $user );
		$this->submit();

		$this->assertContains( [ true, $this->default_board(), $user ], $seen );
	}

	public function test_the_submit_filter_can_refuse_what_the_board_allows(): void {
		wp_set_current_user( self::factory()->user->create() );
		add_filter( SubmissionService::FILTER_CAN_SUBMIT, '__return_false' );

		$response = $this->submit();
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( SubmissionService::ERROR_NOT_ALLOWED, $response->get_data()['code'] );

		$back = SubmitForm::process( $this->form_input( wp_create_nonce( SubmitForm::ACTION ) ), '', '' );
		$this->assertStringContainsString( SubmitForm::PARAM_NOTICE . '=refused', $back );
		$this->assertSame( 0, $this->count_requests() );

		$html = $this->render( SubmitForm::BLOCK );
		$this->assertStringContainsString( 'You cannot suggest features on this board.', $html );
		$this->assertStringNotContainsString( 'name="title"', $html );
	}

	public function test_the_submit_filter_can_allow_what_the_board_refuses(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::NOBODY ] );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringContainsString( 'This board is not taking new requests.', $this->render( SubmitForm::BLOCK ) );

		add_filter( SubmissionService::FILTER_CAN_SUBMIT, '__return_true' );

		$this->assertStringContainsString( 'name="title"', $this->render( SubmitForm::BLOCK ) );
		$this->assertSame( 201, $this->submit()->get_status() );
		$this->assertStringContainsString( SubmitForm::PARAM_NOTICE . '=pending', SubmitForm::process( $this->form_input( wp_create_nonce( SubmitForm::ACTION ) ), '', '' ) );
		$this->assertSame( 2, $this->count_requests() );
	}

	public function test_the_submit_filter_can_let_guests_suggest_where_the_board_wants_users(): void {
		$this->assertStringContainsString( 'Log in to suggest a feature.', $this->render( SubmitForm::BLOCK ) );
		add_filter( SubmissionService::FILTER_CAN_SUBMIT, '__return_true' );

		$html = $this->render( SubmitForm::BLOCK );
		$this->assertStringContainsString( 'name="email"', $html, 'The form asks a guest for a name and an email.' );
		$this->assertSame( 201, $this->submit( [ 'Origin' => home_url() ] )->get_status() );
	}

	/**
	 * Lets only one user vote, whatever the board says.
	 *
	 * @param int $only User ID, or 0 for nobody.
	 */
	private function only_vote_as( int $only ): void {
		add_filter(
			VoteService::FILTER_CAN_VOTE,
			static function ( bool $allowed, int $request_id, int $user_id ) use ( $only ): bool {
				return $only && $only === $user_id;
			},
			10,
			3
		);
	}

	/**
	 * Votes through the REST API.
	 *
	 * @param int                   $request_id Request ID.
	 * @param array<string, string> $headers    Request headers.
	 */
	private function vote( int $request_id, array $headers = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/requests/' . $request_id . '/vote' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * The fields the vote form posts for a logged-in user.
	 *
	 * @param int $request_id Request ID.
	 * @return array<string, string>
	 */
	private function vote_input( int $request_id ): array {
		return [
			'request_id'  => (string) $request_id,
			'redirect_to' => self::PAGE_URL,
			'_wpnonce'    => wp_create_nonce( VoteForm::ACTION ),
		];
	}

	/**
	 * Submits a request to the default board through the REST API.
	 *
	 * @param array<string, string> $headers Request headers.
	 */
	private function submit( array $headers = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/boards/' . $this->default_board() . '/requests' );
		$request->set_body_params( $this->submission() );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * The fields the form posts without JavaScript.
	 *
	 * @param string $nonce Nonce, which the form only includes for logged-in users.
	 * @return array<string, mixed>
	 */
	private function form_input( string $nonce ): array {
		return array_merge(
			$this->submission(),
			[
				'board'       => (string) $this->default_board(),
				'redirect_to' => self::PAGE_URL,
				'_wpnonce'    => $nonce,
			]
		);
	}

	/**
	 * Counts requests in any status.
	 */
	private function count_requests(): int {
		return count(
			get_posts(
				[
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}

	/**
	 * Renders a block.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render( string $name, array $attributes = [] ): string {
		return render_block(
			[
				'blockName'    => $name,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}
}
