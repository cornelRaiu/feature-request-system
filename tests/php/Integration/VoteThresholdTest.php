<?php
/**
 * Vote threshold tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\BoardFields;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\StatusChange;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Notifications\StatusNotifier;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Voting\VoteThreshold;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A board can move a request to another status when it reaches a number of votes. It
 * does so once, as an ordinary status change that the followers are emailed about.
 */
final class VoteThresholdTest extends TestCase {

	/**
	 * Status changes announced during the test: request, old status slug, new status slug.
	 *
	 * @var array<int, array{0: int, 1: string, 2: string}>
	 */
	private $changes = [];

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		$_POST         = [];
		$this->changes = [];
		add_action(
			StatusChange::ACTION,
			function ( int $request_id, $old, $new ): void {
				$this->changes[] = [ $request_id, $old->slug, $new->slug ];
			},
			10,
			3
		);
		$this->set_threshold( 3, 'open', 'under-review' );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		$_POST = [];
		parent::tear_down();
	}

	public function test_the_vote_that_reaches_the_threshold_moves_the_request(): void {
		$request = $this->create_request();

		$this->vote_as_new_users( $request, 2 );
		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ), 'Not yet.' );

		$this->vote_as_new_users( $request, 1 );
		$this->assertSame( [ $this->status( 'under-review' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertSame( [ [ $request, 'open', 'under-review' ] ], $this->changes, 'Announced once, as any status change.' );
		$this->assertNotSame( '', get_post_meta( $request, VoteThreshold::META, true ) );
	}

	public function test_the_followers_and_the_voter_who_reached_it_are_emailed(): void {
		$request  = $this->create_request();
		$follower = $this->create_followers( $request, 1 )[0];
		$this->vote_as_new_users( $request, 2 );
		$last = self::factory()->user->create( [ 'user_email' => 'last-voter@example.org' ] );
		wp_set_current_user( $last );

		$this->vote( $request );

		$this->assertSame( $last, get_current_user_id(), 'The voter is the current user again.' );
		$this->assertSame( 0, get_post_meta( $request, StatusNotifier::META, true )['actor'], 'The board made the change, not the voter.' );
		$this->run_waiting_actions( StatusNotifier::HOOK );
		$emailed = $this->emailed();
		$this->assertContains( get_userdata( $follower )->user_email, $emailed );
		$this->assertContains( 'last-voter@example.org', $emailed, 'The voter follows by voting and hears of the move.' );
		$this->assertCount( 4, $emailed, 'Everyone who follows, once.' );
	}

	public function test_a_request_is_moved_once_so_a_moderator_can_move_it_back(): void {
		$request = $this->create_request();
		$this->vote_as_new_users( $request, 3 );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		wp_set_object_terms( $request, $this->status( 'open' ), StatusTaxonomy::NAME );
		$this->vote_as_new_users( $request, 1 );

		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertCount( 2, $this->changes, 'The move and the moderator moving it back.' );
	}

	public function test_only_requests_in_the_first_status_move(): void {
		$request = $this->create_request();
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );
		$this->changes = [];

		$this->vote_as_new_users( $request, 3 );

		$this->assertSame( [ $this->status( 'planned' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertSame( [], $this->changes );
		$this->assertSame( '', get_post_meta( $request, VoteThreshold::META, true ), 'It can still move if it comes back to the first status.' );
	}

	public function test_a_board_without_a_threshold_and_other_boards_move_nothing(): void {
		$other   = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		$request = $this->create_request();
		wp_set_object_terms( $request, $other, BoardTaxonomy::NAME );
		$this->vote_as_new_users( $request, 3 );
		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ), 'The threshold is the default board’s.' );

		BoardSettings::update( $this->default_board(), [ BoardSettings::THRESHOLD => [ 'votes' => 0 ] ] );
		$here = $this->create_request();
		$this->vote_as_new_users( $here, 3 );
		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $here, StatusTaxonomy::NAME ) );
	}

	public function test_a_threshold_with_a_deleted_status_moves_nothing(): void {
		$status = self::factory()->term->create( [ 'taxonomy' => StatusTaxonomy::NAME ] );
		$this->set_threshold( 3, 'open', get_term( $status )->slug );
		wp_delete_term( $status, StatusTaxonomy::NAME );
		$request = $this->create_request();

		$this->vote_as_new_users( $request, 3 );

		$this->assertSame( [ $this->status( 'open' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
		$this->assertNull( BoardSettings::threshold( $this->default_board() ) );
	}

	public function test_a_vote_without_javascript_moves_the_request_too(): void {
		$request = $this->create_request();
		$this->vote_as_new_users( $request, 2 );
		wp_set_current_user( self::factory()->user->create() );

		VoteForm::process(
			[
				'request_id'  => (string) $request,
				'redirect_to' => home_url( '/' ),
				'_wpnonce'    => wp_create_nonce( VoteForm::ACTION ),
			],
			'',
			''
		);

		$this->assertSame( [ $this->status( 'under-review' ) ], $this->term_ids( $request, StatusTaxonomy::NAME ) );
	}

	public function test_the_threshold_is_set_on_the_boards_form(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		BoardSettings::update( $this->default_board(), [ BoardSettings::THRESHOLD => [] ] );

		ob_start();
		do_action( BoardTaxonomy::NAME . '_add_form_fields', BoardTaxonomy::NAME );
		$html = (string) ob_get_clean();
		$name = BoardFields::FIELD . '[' . BoardSettings::THRESHOLD . ']';
		$this->assertStringContainsString( '<label for="mwtfrs-board-threshold">Vote threshold</label>', $html );
		$this->assertStringContainsString( 'When a request reaches <input type="number" id="mwtfrs-board-threshold" class="small-text" name="' . $name . '[votes]" value=""', $html );
		$this->assertStringContainsString( '<select name="' . $name . '[from]" aria-label="Status to move the request from">', $html );
		$this->assertMatchesRegularExpression( '/\[from\]"[^>]*>(?:<option[^>]*>[^<]*<\/option>)*<option value="' . $this->status( 'open' ) . '" selected=\'selected\'>Open/', $html, 'New requests are suggested.' );
		$this->assertMatchesRegularExpression( '/\[to\]"[^>]*>(?:<option[^>]*>[^<]*<\/option>)*<option value="' . $this->status( 'under-review' ) . '" selected=\'selected\'>Under Review/', $html, 'The next status is suggested.' );

		$_POST = [
			BoardFields::NONCE_FIELD => wp_create_nonce( BoardFields::NONCE_ACTION ),
			BoardFields::FIELD       => [
				BoardSettings::THRESHOLD => [
					'votes' => '50',
					'from'  => (string) $this->status( 'under-review' ),
					'to'    => (string) $this->status( 'planned' ),
				],
			],
		];
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Ideas' ] );
		$this->assertSame(
			[
				'votes' => 50,
				'from'  => $this->status( 'under-review' ),
				'to'    => $this->status( 'planned' ),
			],
			BoardSettings::threshold( $this->default_board() )
		);

		ob_start();
		do_action( BoardTaxonomy::NAME . '_edit_form_fields', get_term( $this->default_board() ), BoardTaxonomy::NAME );
		$this->assertStringContainsString( 'name="' . $name . '[votes]" value="50"', (string) ob_get_clean() );

		$_POST[ BoardFields::FIELD ][ BoardSettings::THRESHOLD ]['votes'] = '';
		wp_update_term( $this->default_board(), BoardTaxonomy::NAME, [ 'name' => 'Ideas' ] );
		$this->assertNull( BoardSettings::threshold( $this->default_board() ), 'An empty number moves nothing.' );
	}

	/**
	 * Gives the default board a threshold.
	 *
	 * @param int    $votes Number of votes.
	 * @param string $from  Slug of the status to move from.
	 * @param string $to    Slug of the status to move to.
	 */
	private function set_threshold( int $votes, string $from, string $to ): void {
		BoardSettings::update(
			$this->default_board(),
			[
				BoardSettings::THRESHOLD => [
					'votes' => $votes,
					'from'  => $this->status( $from ),
					'to'    => $this->status( $to ),
				],
			]
		);
	}

	/**
	 * Votes for a request as new users.
	 *
	 * @param int $request_id Request ID.
	 * @param int $count      How many users vote.
	 */
	private function vote_as_new_users( int $request_id, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			wp_set_current_user( self::factory()->user->create() );
			$this->assertSame( 200, $this->vote( $request_id )->get_status() );
		}
	}

	/**
	 * Votes for a request through the REST API as the current user.
	 *
	 * @param int $request_id Request ID.
	 */
	private function vote( int $request_id ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/requests/' . $request_id . '/vote' ) );
	}
}
