<?php
/**
 * Board rendering tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\BoardQuery;
use FeatureRequestSystem\Frontend\RequestItem;
use FeatureRequestSystem\Frontend\RequestNotice;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Frontend\VoteStore;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;

/**
 * Renders the real block, the way a page does.
 */
final class BoardRenderTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
	}

	public function tear_down(): void {
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_board_lists_its_published_requests_with_most_votes_first(): void {
		$this->request_with_votes( 'Few votes', 1 );
		$this->request_with_votes( 'Most votes', 5 );
		$this->request_with_votes( 'Some votes', 3 );
		$this->create_request(
			[
				'post_title'  => 'Waiting for moderation',
				'post_status' => 'pending',
			]
		);
		$other_board = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		wp_set_object_terms( $this->create_request( [ 'post_title' => 'On another board' ] ), $other_board, BoardTaxonomy::NAME );

		$html = $this->render();

		$this->assertTitlesInOrder( [ 'Most votes', 'Some votes', 'Few votes' ], $html );
		$this->assertStringNotContainsString( 'Waiting for moderation', $html );
		$this->assertStringNotContainsString( 'On another board', $html );
	}

	public function test_newest_first_when_sorted_by_date(): void {
		$this->create_request(
			[
				'post_title' => 'Older',
				'post_date'  => '2026-01-01 10:00:00',
			]
		);
		$this->create_request(
			[
				'post_title' => 'Newer',
				'post_date'  => '2026-06-01 10:00:00',
			]
		);
		$_GET[ BoardQuery::PARAM_SORT ] = BoardQuery::SORT_NEW;

		$this->assertTitlesInOrder( [ 'Newer', 'Older' ], $this->render() );
	}

	public function test_board_can_be_filtered_by_status_category_and_search(): void {
		$planned  = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$started  = $this->create_request( [ 'post_title' => 'Export to CSV' ] );
		$category = self::factory()->term->create_and_get(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Mobile',
			]
		);
		wp_set_object_terms( $planned, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $planned, $category->term_id, CategoryTaxonomy::NAME );
		wp_set_object_terms( $started, $this->status( 'in-progress' ), StatusTaxonomy::NAME );

		$_GET = [ BoardQuery::PARAM_STATUS => 'planned' ];
		$this->assertTitles( [ 'Dark mode' ], [ 'Export to CSV' ], $this->render() );

		$_GET = [ BoardQuery::PARAM_CATEGORY => $category->slug ];
		$this->assertTitles( [ 'Dark mode' ], [ 'Export to CSV' ], $this->render() );

		$_GET = [ BoardQuery::PARAM_SEARCH => 'CSV' ];
		$this->assertTitles( [ 'Export to CSV' ], [ 'Dark mode' ], $this->render() );
	}

	public function test_closed_requests_are_hidden_unless_asked_for(): void {
		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		$shipped = $this->create_request( [ 'post_title' => 'Two-factor login' ] );
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );

		$this->assertTitles( [ 'Dark mode' ], [ 'Two-factor login' ], $this->render() );

		$_GET[ BoardQuery::PARAM_STATUS ] = BoardQuery::STATUS_ALL;
		$this->assertTitles( [ 'Dark mode', 'Two-factor login' ], [], $this->render() );

		$_GET[ BoardQuery::PARAM_STATUS ] = 'shipped';
		$this->assertTitles( [ 'Two-factor login' ], [ 'Dark mode' ], $this->render() );
	}

	public function test_long_boards_are_split_into_pages(): void {
		$this->request_with_votes( 'First', 3 );
		$this->request_with_votes( 'Second', 2 );
		$this->request_with_votes( 'Third', 1 );

		$first_page = $this->render( [ 'perPage' => 2 ] );
		$this->assertTitles( [ 'First', 'Second' ], [ 'Third' ], $first_page );
		$this->assertStringContainsString( BoardQuery::PARAM_PAGE . '=2', $first_page );

		$_GET[ BoardQuery::PARAM_PAGE ] = '2';
		$this->assertTitles( [ 'Third' ], [ 'First', 'Second' ], $this->render( [ 'perPage' => 2 ] ) );
	}

	public function test_votable_requests_render_a_working_form_with_the_count(): void {
		$request = $this->request_with_votes( 'Dark mode', 4 );

		$html = $this->render();

		$this->assertStringContainsString( 'name="action" value="' . VoteForm::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="request_id" value="' . $request . '"', $html );
		$this->assertStringContainsString( 'aria-pressed="false"', $html, 'The server renders every button unpressed.' );
		$this->assertMatchesRegularExpression( '/class="mwtfrs-vote__count"[^>]*>4</', $html );
	}

	public function test_closed_requests_and_boards_with_voting_off_show_counts_without_a_form(): void {
		$shipped = $this->request_with_votes( 'Shipped already', 9 );
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		$_GET[ BoardQuery::PARAM_STATUS ] = BoardQuery::STATUS_ALL;

		$html = $this->render();
		$this->assertStringContainsString( 'mwtfrs-vote--closed', $html );
		$this->assertStringNotContainsString( 'name="request_id"', $html );

		wp_set_object_terms( $shipped, $this->status( 'open' ), StatusTaxonomy::NAME );
		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::NOBODY ] );
		$this->assertStringNotContainsString( 'name="request_id"', $this->render() );
	}

	public function test_titles_and_descriptions_are_escaped(): void {
		$this->create_request(
			[
				'post_title'   => '<img src=x onerror=alert(1)>Hover me',
				'post_content' => '<script>alert(2)</script>Details',
			]
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script>alert(2)', $html );
		$this->assertStringContainsString( 'Hover me', $html );
	}

	public function test_every_guest_gets_the_same_html_so_pages_can_be_cached(): void {
		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$request                          = $this->request_with_votes( 'Dark mode', 0 );
		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'b2', 16 );
		VoteService::cast( $request );

		$voter_html = $this->render();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$stranger_html = $this->render();

		$this->assertSame( $this->normalize( $stranger_html ), $this->normalize( $voter_html ) );
		$this->assertStringNotContainsString( 'aria-pressed="true"', $voter_html );
	}

	public function test_logged_in_users_get_the_same_html_apart_from_their_nonces(): void {
		$request = $this->request_with_votes( 'Dark mode', 0 );
		$voter   = self::factory()->user->create();
		$other   = self::factory()->user->create();
		wp_set_current_user( $voter );
		VoteService::cast( $request );

		$voter_html = $this->render();
		wp_set_current_user( $other );
		$other_html = $this->render();

		$this->assertSame( $this->normalize( $other_html ), $this->normalize( $voter_html ) );
		$this->assertStringContainsString( 'name="_wpnonce"', $voter_html );
	}

	public function test_guests_get_no_nonce_in_the_html(): void {
		$this->request_with_votes( 'Dark mode', 0 );

		$this->assertStringNotContainsString( '_wpnonce', $this->render() );
	}

	public function test_result_of_a_vote_without_javascript_is_shown_on_that_request(): void {
		$voted = $this->request_with_votes( 'Dark mode', 1 );
		$this->request_with_votes( 'Export to CSV', 0 );
		$_GET  = [
			RequestNotice::PARAM  => 'added',
			RequestNotice::PARAM_REQUEST => (string) $voted,
		];

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'data-wp-text="context.message">Your vote was added.</span>' ), 'Shown once, as text.' );
		preg_match( '#id="' . RequestItem::dom_id( $voted ) . '".*?</li>#s', $html, $item );
		$this->assertStringContainsString( '<p class="mwtfrs-request__message"', $item[0], 'The message is not hidden on the voted request.' );
		$this->assertStringContainsString( 'Your vote was added.', $item[0] );
	}

	public function test_login_notice_shows_a_login_link(): void {
		$request = $this->request_with_votes( 'Dark mode', 0 );
		$_GET    = [
			RequestNotice::PARAM  => 'login',
			RequestNotice::PARAM_REQUEST => (string) $request,
		];

		$html = $this->render();

		$this->assertStringContainsString( 'Log in to vote on this request.', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*wp-login\.php[^"]*"[^>]*>Log in</a>#', $html );
	}

	public function test_empty_board_and_empty_results_have_their_own_messages(): void {
		$this->assertStringContainsString( 'No requests yet.', $this->render() );

		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		$_GET[ BoardQuery::PARAM_SEARCH ] = 'nothing matches this';
		$this->assertStringContainsString( 'No requests match these filters.', $this->render() );
	}

	public function test_a_board_with_only_closed_requests_says_nothing_is_open(): void {
		$shipped = $this->create_request( [ 'post_title' => 'Two-factor login' ] );
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );

		$this->assertStringContainsString( 'No requests are open for voting right now.', $this->render() );
	}

	public function test_a_board_that_no_longer_exists_falls_back_to_the_default_board(): void {
		$this->create_request( [ 'post_title' => 'Dark mode' ] );

		$this->assertStringContainsString( 'Dark mode', $this->render( [ 'board' => 999999 ] ) );
	}

	public function test_shortcode_renders_the_chosen_board(): void {
		$mobile = self::factory()->term->create_and_get(
			[
				'taxonomy' => BoardTaxonomy::NAME,
				'name'     => 'Mobile app',
			]
		);
		wp_set_object_terms( $this->create_request( [ 'post_title' => 'Offline mode' ] ), $mobile->term_id, BoardTaxonomy::NAME );
		$this->create_request( [ 'post_title' => 'Dark mode' ] );

		$html = do_shortcode( '[' . Blocks::SHORTCODE . ' board="' . $mobile->slug . '"]' );

		$this->assertTitles( [ 'Offline mode' ], [ 'Dark mode' ], $html );
		$this->assertStringContainsString( 'data-wp-interactive="' . VoteStore::NAME . '"', $html );
	}

	/**
	 * Renders the board block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render( array $attributes = [] ): string {
		return render_block(
			[
				'blockName'    => Board::BLOCK,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
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

	/**
	 * Removes what legitimately differs between two renders: generated IDs and nonces.
	 *
	 * @param string $html Board HTML.
	 */
	private function normalize( string $html ): string {
		$html = (string) preg_replace( '/mwtfrs-board-\d+/', 'mwtfrs-board-N', $html );

		return (string) preg_replace( '/name="_wpnonce" value="[^"]*"/', 'name="_wpnonce" value="N"', $html );
	}

	/**
	 * Asserts which titles are on the board and which are not.
	 *
	 * @param string[] $present Titles that must appear.
	 * @param string[] $absent  Titles that must not appear.
	 * @param string   $html    Board HTML.
	 */
	private function assertTitles( array $present, array $absent, string $html ): void {
		foreach ( $present as $title ) {
			$this->assertStringContainsString( '>' . $title . '</a>', $html, "{$title} is listed." );
		}
		foreach ( $absent as $title ) {
			$this->assertStringNotContainsString( '>' . $title . '</a>', $html, "{$title} is not listed." );
		}
	}

	/**
	 * Asserts that titles appear in the given order.
	 *
	 * @param string[] $titles Titles, first to last.
	 * @param string   $html   Board HTML.
	 */
	private function assertTitlesInOrder( array $titles, string $html ): void {
		$positions = array_map(
			static function ( string $title ) use ( $html ) {
				return strpos( $html, '>' . $title . '</a>' );
			},
			$titles
		);

		$this->assertNotContains( false, $positions, 'Every title is listed.' );
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions );
	}
}
