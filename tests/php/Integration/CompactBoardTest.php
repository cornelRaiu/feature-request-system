<?php
/**
 * Compact board tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\Board;
use FeatureRequestSystem\Frontend\BoardQuery;
use FeatureRequestSystem\Frontend\RequestNotice;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;

/**
 * Renders the board block in its compact form, the Feature Request List, the way a page does.
 */
final class CompactBoardTest extends TestCase {

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

	public function test_a_list_shows_the_most_voted_requests_and_nothing_to_search_filter_or_page_with(): void {
		$this->request_with_votes( 'Second', 2 );
		$this->request_with_votes( 'First', 3 );
		$this->request_with_votes( 'Third', 1 );

		$html = $this->render( [ 'perPage' => 2 ] );

		$this->assertTitlesInOrder( [ 'First', 'Second' ], $html );
		$this->assertStringNotContainsString( '>Third</a>', $html );
		$this->assertMatchesRegularExpression( '/^<div\s+class="mwtfrs-board mwtfrs-board--compact wp-block-mwtfrs-board"/', $html );
		$this->assertStringContainsString( '<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>', $html, 'What a vote says to screen readers belongs to this list.' );
		$this->assertStringNotContainsString( '<form class="mwtfrs-board__filters"', $html );
		$this->assertStringNotContainsString( 'mwtfrs-board__pagination', $html );
		$this->assertStringNotContainsString( BoardQuery::PARAM_PAGE, $html );
	}

	public function test_a_list_ignores_the_boards_filters_in_the_url(): void {
		$this->request_with_votes( 'Dark mode', 3 );
		$this->request_with_votes( 'Export to CSV', 1 );
		$shipped = $this->request_with_votes( 'Two-factor login', 9 );
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		$plain = $this->normalize( $this->render( [ 'perPage' => 1 ] ) );

		$_GET = [
			BoardQuery::PARAM_SEARCH => 'CSV',
			BoardQuery::PARAM_STATUS => BoardQuery::STATUS_ALL,
			BoardQuery::PARAM_SORT   => BoardQuery::SORT_NEW,
			BoardQuery::PARAM_PAGE   => '2',
		];

		$this->assertSame( $plain, $this->normalize( $this->render( [ 'perPage' => 1 ] ) ) );
		$this->assertStringContainsString( '>Dark mode</a>', $plain );
	}

	public function test_a_list_can_show_the_newest_first(): void {
		$dates = [
			'Oldest, with the most votes' => '2025-12-01 10:00:00',
			'Older'                       => '2026-01-01 10:00:00',
			'Newer'                       => '2026-02-01 10:00:00',
		];
		foreach ( $dates as $title => $date ) {
			$request = $this->create_request(
				[
					'post_title' => $title,
					'post_date'  => $date,
				]
			);
			update_post_meta( $request, RequestPostType::META_VOTES, 'Oldest, with the most votes' === $title ? 9 : 0 );
		}

		$this->assertTitlesInOrder( [ 'Oldest, with the most votes', 'Newer', 'Older' ], $this->render() );
		$this->assertTitlesInOrder( [ 'Newer', 'Older', 'Oldest, with the most votes' ], $this->render( [ 'sort' => BoardQuery::SORT_NEW ] ) );
	}

	public function test_a_list_shows_what_is_open_for_voting_unless_it_is_given_a_status(): void {
		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		$planned = $this->create_request( [ 'post_title' => 'Export to CSV' ] );
		$shipped = $this->create_request( [ 'post_title' => 'Two-factor login' ] );
		wp_set_object_terms( $planned, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $shipped, $this->status( 'shipped' ), StatusTaxonomy::NAME );

		$this->assertTitles( [ 'Dark mode', 'Export to CSV' ], [ 'Two-factor login' ], $this->render() );
		$this->assertTitles( [ 'Export to CSV' ], [ 'Dark mode', 'Two-factor login' ], $this->render( [ 'status' => 'planned' ] ) );
		$this->assertTitles( [ 'Two-factor login' ], [ 'Dark mode', 'Export to CSV' ], $this->render( [ 'status' => 'shipped' ] ) );
		$this->assertTitles( [ 'Dark mode', 'Export to CSV', 'Two-factor login' ], [], $this->render( [ 'status' => BoardQuery::STATUS_ALL ] ) );
	}

	public function test_a_list_shows_no_more_than_twenty_requests(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->create_request();
		}

		$this->assertSame( 20, substr_count( $this->render( [ 'perPage' => 50 ] ), 'class="mwtfrs-request__title"' ) );
		$this->assertSame( 1, substr_count( $this->render( [ 'perPage' => 0 ] ), 'class="mwtfrs-request__title"' ), 'At least one.' );
	}

	public function test_a_list_covers_every_board_unless_one_is_chosen(): void {
		$mobile = $this->board( 'Mobile app' );
		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		wp_set_object_terms( $this->create_request( [ 'post_title' => 'Offline mode' ] ), $mobile, BoardTaxonomy::NAME );

		$this->assertTitles( [ 'Dark mode', 'Offline mode' ], [], $this->render() );
		$this->assertTitles( [ 'Offline mode' ], [ 'Dark mode' ], $this->render( [ 'board' => $mobile ] ) );
		$this->assertTitles( [ 'Dark mode' ], [ 'Offline mode' ], $this->render( [ 'board' => $this->default_board() ] ) );
		$this->assertTitles( [ 'Dark mode', 'Offline mode' ], [], $this->render( [ 'board' => 999999 ] ), 'A board that no longer exists limits nothing.' );
	}

	public function test_each_request_follows_the_voting_rule_of_its_own_board(): void {
		$closed = $this->board( 'Closed board' );
		BoardSettings::update( $closed, [ BoardSettings::VOTING => BoardSettings::NOBODY ] );
		$open = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$off  = $this->create_request( [ 'post_title' => 'Offline mode' ] );
		wp_set_object_terms( $off, $closed, BoardTaxonomy::NAME );

		$html = $this->render();

		$this->assertStringContainsString( 'name="request_id" value="' . $open . '"', $html );
		$this->assertStringNotContainsString( 'name="request_id" value="' . $off . '"', $html, 'Its board takes no votes.' );
		$this->assertSame( 1, substr_count( $html, 'mwtfrs-vote--closed' ) );
	}

	public function test_the_script_asks_for_a_guests_votes_only_where_a_board_lets_guests_vote(): void {
		$guests = $this->board( 'Open to guests' );
		BoardSettings::update( $guests, [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		$welcome = $this->create_request( [ 'post_title' => 'Offline mode' ] );
		wp_set_object_terms( $welcome, $guests, BoardTaxonomy::NAME );

		$this->assertTrue( $this->context_of( $this->render() )['guestVotes'], 'One request is on a board where guests vote.' );
		$this->assertFalse( $this->context_of( $this->render( [ 'board' => $this->default_board() ] ) )['guestVotes'] );

		wp_set_object_terms( $welcome, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		$this->assertFalse( $this->context_of( $this->render( [ 'status' => BoardQuery::STATUS_ALL ] ) )['guestVotes'], 'Nobody votes on a closed request.' );
	}

	public function test_a_list_leaves_out_the_summary_and_the_categories_and_adds_no_headings(): void {
		$request = $this->create_request(
			[
				'post_title'   => 'Dark mode',
				'post_excerpt' => 'Easier on the eyes at night.',
			]
		);
		$category = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Appearance',
			]
		);
		wp_set_object_terms( $request, $category, CategoryTaxonomy::NAME );
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		$list  = $this->render( [ 'status' => 'planned' ] );
		$board = $this->render_block( [] );

		$this->assertStringContainsString( '<p class="mwtfrs-request__title"><a href="' . esc_url( get_permalink( $request ) ) . '">Dark mode</a></p>', $list );
		$this->assertStringNotContainsString( '<h3', $list );
		$this->assertStringNotContainsString( 'Easier on the eyes', $list );
		$this->assertStringNotContainsString( 'Appearance', $list );
		$this->assertStringContainsString( '>Planned</span>', $list );
		$this->assertStringContainsString( '<h3 class="mwtfrs-request__title">', $board, 'On the board, titles are headings.' );
		$this->assertStringContainsString( 'Easier on the eyes', $board );
	}

	public function test_see_all_leads_to_the_page_that_shows_the_board(): void {
		$this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->assertStringNotContainsString( 'mwtfrs-board__more', $this->render(), 'No page shows the board yet.' );

		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		BoardSettings::update( $this->default_board(), [ BoardSettings::PAGE => $page ] );
		$link = '<p class="mwtfrs-board__more"><a href="' . esc_url( get_permalink( $page ) ) . '">See all requests</a></p>';

		$this->assertStringContainsString( $link, $this->render(), 'Every board, on a site with one board.' );
		$this->assertStringContainsString( $link, $this->render( [ 'board' => $this->default_board() ] ) );

		$mobile = $this->board( 'Mobile app' );
		$this->assertStringNotContainsString( 'mwtfrs-board__more', $this->render(), 'With two boards, no one page shows every request.' );
		$this->assertStringContainsString( $link, $this->render( [ 'board' => $this->default_board() ] ) );
		$this->assertStringNotContainsString( 'mwtfrs-board__more', $this->render( [ 'board' => $mobile ] ), 'No page shows this board.' );

		$this->go_to( get_permalink( $page ) );
		$this->assertStringNotContainsString( 'mwtfrs-board__more', $this->render( [ 'board' => $this->default_board() ] ), 'Not on the page it would lead to.' );
	}

	public function test_a_list_does_not_become_the_boards_page(): void {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:' . Board::BLOCK . ' {"compact":true} /-->',
			]
		);
		$this->create_request( [ 'post_title' => 'Dark mode' ] );

		$this->go_to( get_permalink( $page ) );
		the_post();
		$content = (string) apply_filters( 'the_content', get_the_content() );

		$this->assertStringContainsString( '>Dark mode</a>', $content );
		$this->assertSame( '', BoardSettings::page_url( $this->default_board() ) );
	}

	public function test_a_request_shown_twice_has_two_ids_and_each_vote_comes_back_to_its_own(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request( [ 'post_title' => 'Dark mode' ] );

		$html = $this->render() . $this->render() . $this->render_block( [] );

		preg_match_all( '/id="([^"]*-request-' . $request . ')"/', $html, $ids );
		$this->assertCount( 3, $ids[1] );
		$this->assertCount( 3, array_unique( $ids[1] ), 'Two lists and the board.' );
		foreach ( $ids[1] as $id ) {
			$this->assertSame( 1, substr_count( $html, 'name="redirect_to" value="' . esc_url( home_url() . '#' . $id ) . '"' ), $id );
		}
	}

	public function test_result_of_a_vote_without_javascript_is_shown_on_that_request(): void {
		$voted = $this->request_with_votes( 'Dark mode', 1 );
		$this->request_with_votes( 'Export to CSV', 0 );
		$_GET = [
			RequestNotice::PARAM         => 'added',
			RequestNotice::PARAM_REQUEST => (string) $voted,
		];

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'data-wp-text="context.message">Your vote was added.</span>' ) );
		preg_match( '#-request-' . $voted . '".*?</li>#s', $html, $item );
		$this->assertStringContainsString( 'Your vote was added.', $item[0] );
	}

	public function test_every_guest_gets_the_same_html_so_pages_can_be_cached(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$request                          = $this->request_with_votes( 'Dark mode', 0 );
		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'b2', 16 );
		VoteService::cast( $request );

		$voter_html = $this->render();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$stranger_html = $this->render();

		$this->assertSame( $this->normalize( $stranger_html ), $this->normalize( $voter_html ) );
		$this->assertStringNotContainsString( 'aria-pressed="true"', $voter_html );
		$this->assertStringNotContainsString( '_wpnonce', $voter_html );
		$this->assertStringContainsString( 'name="action" value="' . VoteForm::ACTION . '"', $voter_html );
	}

	public function test_a_longer_list_of_every_board_asks_the_database_no_more_questions(): void {
		global $wpdb;

		$mobile  = $this->board( 'Mobile app' );
		$queries = [];
		foreach ( [ 3, 20 ] as $shown ) {
			while ( count( get_posts( [ 'post_type' => RequestPostType::NAME, 'numberposts' => -1, 'fields' => 'ids' ] ) ) < $shown ) {
				$request = $this->request_with_votes( 'Request ' . wp_generate_password( 8, false ), 3 );
				wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );
				if ( $request % 2 ) {
					wp_set_object_terms( $request, $mobile, BoardTaxonomy::NAME );
				}
			}

			// As on a page view: nothing in the object cache yet.
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$html   = $this->render( [ 'perPage' => $shown ] );

			$queries[ $shown ] = $wpdb->num_queries - $before;
			$this->assertSame( $shown, substr_count( $html, 'class="mwtfrs-request__title"' ) );
		}

		$this->assertSame( $queries[3], $queries[20], 'The boards of the requests and their voting rules are read together, not one request at a time.' );
	}

	public function test_a_list_does_not_count_the_requests_it_does_not_show(): void {
		$this->create_request();
		$counted = [];
		$watch   = static function ( string $sql ) use ( &$counted ): string {
			if ( false !== strpos( $sql, 'FOUND_ROWS' ) ) {
				$counted[] = $sql;
			}

			return $sql;
		};

		add_filter( 'query', $watch );
		$this->render();
		$this->assertSame( [], $counted, 'It has no pages.' );
		$this->render_block( [] );
		remove_filter( 'query', $watch );

		$this->assertNotSame( [], $counted, 'The board counts them, for its pages.' );
	}

	public function test_an_empty_list_says_so(): void {
		$this->assertStringContainsString( '<p class="mwtfrs-board__empty">Nothing to show here yet.</p>', $this->render() );
	}

	public function test_shortcode_renders_a_list(): void {
		$mobile = $this->board( 'Mobile app' );
		$this->request_with_votes( 'Dark mode', 5 );
		// On the mobile board, the fewer votes a request has, the newer it is.
		foreach ( [ 'Offline mode', 'Widgets', 'Sync' ] as $votes => $title ) {
			$request = $this->create_request(
				[
					'post_title' => $title,
					'post_date'  => '2026-01-0' . ( 3 - $votes ) . ' 10:00:00',
				]
			);
			update_post_meta( $request, RequestPostType::META_VOTES, $votes );
			wp_set_object_terms( $request, $mobile, BoardTaxonomy::NAME );
		}
		wp_set_object_terms( $request, $this->status( 'planned' ), StatusTaxonomy::NAME );

		$this->assertTitlesInOrder( [ 'Dark mode', 'Sync', 'Widgets', 'Offline mode' ], do_shortcode( '[' . Blocks::LIST_SHORTCODE . ']' ) );
		$this->assertStringContainsString( 'class="mwtfrs-board mwtfrs-board--compact ', do_shortcode( '[' . Blocks::LIST_SHORTCODE . ']' ) );

		$html = do_shortcode( '[' . Blocks::LIST_SHORTCODE . ' board="mobile-app" count="2" sort="new"]' );
		$this->assertTitlesInOrder( [ 'Offline mode', 'Widgets' ], $html );
		$this->assertTitles( [], [ 'Dark mode', 'Sync' ], $html );

		$this->assertTitles( [ 'Sync' ], [ 'Dark mode', 'Offline mode', 'Widgets' ], do_shortcode( '[' . Blocks::LIST_SHORTCODE . ' status="planned"]' ) );
	}

	public function test_the_list_shortcode_shows_five_requests_unless_told_otherwise(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->create_request();
		}

		$this->assertSame( 5, substr_count( do_shortcode( '[' . Blocks::LIST_SHORTCODE . ']' ), 'class="mwtfrs-request__title"' ) );
		$this->assertSame( 6, substr_count( do_shortcode( '[' . Blocks::LIST_SHORTCODE . ' count="6"]' ), 'class="mwtfrs-request__title"' ) );
	}

	/**
	 * Renders the board block in its compact form.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render( array $attributes = [] ): string {
		return $this->render_block( [ 'compact' => true ] + $attributes );
	}

	/**
	 * Renders the board block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_block( array $attributes ): string {
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
	 * Returns the context the block gives its script.
	 *
	 * @param string $html Block HTML.
	 * @return array<string, mixed>
	 */
	private function context_of( string $html ): array {
		preg_match( "/data-wp-context='([^']*)'/", $html, $context );

		return (array) json_decode( $context[1], true );
	}

	/**
	 * Creates a board.
	 *
	 * @param string $name Board name.
	 */
	private function board( string $name ): int {
		return self::factory()->term->create(
			[
				'taxonomy' => BoardTaxonomy::NAME,
				'name'     => $name,
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
	 * Removes what legitimately differs between two renders: generated IDs.
	 *
	 * @param string $html List HTML.
	 */
	private function normalize( string $html ): string {
		return (string) preg_replace( '/mwtfrs-list-\d+/', 'mwtfrs-list-N', $html );
	}

	/**
	 * Asserts which titles are on the list and which are not.
	 *
	 * @param string[] $present Titles that must appear.
	 * @param string[] $absent  Titles that must not appear.
	 * @param string   $html    List HTML.
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
	 * @param string   $html   List HTML.
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
