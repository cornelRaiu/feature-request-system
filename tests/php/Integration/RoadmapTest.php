<?php
/**
 * Roadmap tests.
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
use FeatureRequestSystem\Frontend\Roadmap;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;

/**
 * Renders the roadmap block the way a page does: its columns, their requests and "Show more".
 */
final class RoadmapTest extends TestCase {

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

	public function test_a_column_for_each_status_on_the_roadmap_in_workflow_order(): void {
		$this->assertSame( [ 'Planned', 'In Progress', 'Shipped' ], $this->columns( $this->render() ), 'The statuses the plugin starts with.' );

		update_term_meta( $this->status( 'open' ), StatusTaxonomy::META_ROADMAP, true );
		update_term_meta( $this->status( 'shipped' ), StatusTaxonomy::META_ROADMAP, false );
		update_term_meta( $this->status( 'in-progress' ), StatusTaxonomy::META_ORDER, 5 );

		$this->assertSame( [ 'In Progress', 'Open', 'Planned' ], $this->columns( $this->render() ) );
	}

	public function test_each_column_shows_the_requests_in_its_status_most_votes_first(): void {
		$this->request( 'Dark mode', 'planned', 2 );
		$this->request( 'Export to CSV', 'planned', 5 );
		$this->request( 'Calendar view', 'in-progress', 1 );
		$this->request( 'Two-factor login', 'shipped', 9 );
		$this->request( 'Widgets', 'open', 40 );
		$this->request( 'Waiting for approval', 'planned', 50, [ 'post_status' => 'pending' ] );
		$this->request( 'Behind a password', 'planned', 50, [ 'post_password' => 'secret' ] );

		$html = $this->render();

		$this->assertSame( [ 'Export to CSV', 'Dark mode' ], $this->titles( $this->column( $html, 'planned' ) ) );
		$this->assertSame( [ 'Calendar view' ], $this->titles( $this->column( $html, 'in-progress' ) ) );
		$this->assertSame( [ 'Two-factor login' ], $this->titles( $this->column( $html, 'shipped' ) ) );
		$this->assertStringNotContainsString( 'Widgets', $html, 'Open is not on the roadmap.' );
		$this->assertStringNotContainsString( 'Waiting for approval', $html );
		$this->assertStringNotContainsString( 'Behind a password', $html );
	}

	public function test_a_column_heading_names_the_status_and_counts_its_requests(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->request( 'Planned ' . $i, 'planned', 0 );
		}
		$this->request( 'Calendar view', 'in-progress', 0 );

		$html    = $this->render();
		$planned = $this->column( $html, 'planned' );

		$this->assertMatchesRegularExpression( '#<h3 class="mwtfrs-roadmap__heading" id="mwtfrs-roadmap-\d+-planned">\s*<span class="mwtfrs-status">Planned</span>\s*<span class="mwtfrs-roadmap__count" aria-hidden="true">12</span>\s*<span class="mwtfrs-sr-only">\s*12 requests\s*</span>#', $planned, 'Every request in the status, also those the column does not show yet.' );
		$this->assertStringContainsString( '--mwtfrs-status-color: #6d4fc2', $planned );
		$this->assertMatchesRegularExpression( '#<span class="mwtfrs-sr-only">\s*1 request\s*</span>#', $this->column( $html, 'in-progress' ) );
	}

	public function test_a_column_shows_ten_requests_and_show_more_adds_ten_more(): void {
		for ( $i = 1; $i <= 23; $i++ ) {
			$this->request( 'Planned ' . $i, 'planned', 100 - $i );
		}
		$this->request( 'Calendar view', 'in-progress', 1 );

		$planned = $this->column( $this->render(), 'planned' );
		$this->assertSame( $this->numbered( 1, 10 ), $this->titles( $planned ) );
		$more = $this->more_link( $planned );
		$this->assertSame( add_query_arg( Roadmap::PARAM_PREFIX . 'planned', 2, home_url() ), strtok( $more, '#' ), 'The same page, with one more page of this column.' );
		$this->assertMatchesRegularExpression( '/#mwtfrs-roadmap-\d+-request-\d+$/', $more, 'It leads to the first request it adds.' );
		$this->assertSame( '', $this->more_link( $this->column( $this->render(), 'in-progress' ) ), 'Everything in it is shown.' );

		$_GET    = [ Roadmap::PARAM_PREFIX . 'planned' => '2' ];
		$html    = $this->render();
		$planned = $this->column( $html, 'planned' );
		$this->assertSame( $this->numbered( 1, 20 ), $this->titles( $planned ) );
		$this->assertSame( 'Planned 11', get_the_title( (int) $this->contexts( $planned )[0]['ids'][10] ) );
		$this->assertStringEndsWith( '#' . $this->normalize( $this->dom_id( $planned, (int) $this->contexts( $planned )[0]['ids'][10] ) ), $this->normalize( $more ), 'The link led to the first request this page added.' );
		$this->assertSame( add_query_arg( Roadmap::PARAM_PREFIX . 'planned', 3, home_url() ), strtok( $this->more_link( $planned ), '#' ) );
		$this->assertSame( [ 'Calendar view' ], $this->titles( $this->column( $html, 'in-progress' ) ), 'The other columns stay as they are.' );

		$_GET = [ Roadmap::PARAM_PREFIX . 'planned' => '3' ];
		$this->assertSame( $this->numbered( 1, 23 ), $this->titles( $this->column( $this->render(), 'planned' ) ) );
		$this->assertSame( '', $this->more_link( $this->column( $this->render(), 'planned' ) ), 'Nothing more to show.' );
	}

	public function test_the_url_cannot_make_a_column_show_every_request(): void {
		for ( $i = 1; $i <= ( Roadmap::MAX_PAGES * Roadmap::PER_PAGE ) + 1; $i++ ) {
			$this->request( 'Planned ' . $i, 'planned', 0 );
		}

		$_GET = [ Roadmap::PARAM_PREFIX . 'planned' => '1000' ];
		$html = $this->column( $this->render(), 'planned' );
		$this->assertSame( Roadmap::MAX_PAGES * Roadmap::PER_PAGE, substr_count( $html, 'class="mwtfrs-request__title"' ) );
		$this->assertSame( '', $this->more_link( $html ), 'Past the last page there is no link.' );

		foreach ( [ '0', '-3', 'many' ] as $value ) {
			$_GET = [ Roadmap::PARAM_PREFIX . 'planned' => $value ];
			$this->assertSame( Roadmap::PER_PAGE, substr_count( $this->column( $this->render(), 'planned' ), 'class="mwtfrs-request__title"' ), $value );
		}
	}

	public function test_show_more_works_for_a_status_of_any_name(): void {
		foreach ( [ 'В работе', 'All' ] as $name ) {
			$status = self::factory()->term->create(
				[
					'taxonomy' => StatusTaxonomy::NAME,
					'name'     => $name,
				]
			);
			update_term_meta( $status, StatusTaxonomy::META_ROADMAP, true );
			$slug = get_term( $status )->slug;
			for ( $i = 1; $i <= 12; $i++ ) {
				$this->request( $name . ' ' . $i, $slug, 0 );
			}
		}
		$this->request( 'Calendar view', 'in-progress', 0 );

		foreach ( [ 'В работе', 'All' ] as $name ) {
			$_GET   = [];
			$slug   = get_term_by( 'name', $name, StatusTaxonomy::NAME )->slug;
			$column = $this->column( $this->render(), $slug );
			$this->assertCount( 10, $this->titles( $column ), $name . ': ten of its own requests.' );
			$this->assertStringNotContainsString( 'Calendar view', $column, $name . ': only its own requests.' );

			// Follows the link the way PHP reads a URL.
			parse_str( (string) wp_parse_url( $this->more_link( $column ), PHP_URL_QUERY ), $_GET );
			$this->assertCount( 12, $this->titles( $this->column( $this->render(), $slug ) ), $name . ': Show more shows more.' );
		}
	}

	public function test_show_more_and_votes_without_javascript_keep_what_the_other_columns_show(): void {
		wp_set_current_user( self::factory()->user->create() );
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->request( 'Planned ' . $i, 'planned', 0 );
			$this->request( 'Underway ' . $i, 'in-progress', 0 );
		}
		$_GET = [
			Roadmap::PARAM_PREFIX . 'in-progress' => '2',
			'unrelated'                           => 'dropped',
		];

		$html = $this->render();

		$expected = add_query_arg(
			[
				Roadmap::PARAM_PREFIX . 'in-progress' => 2,
				Roadmap::PARAM_PREFIX . 'planned'     => 2,
			],
			home_url()
		);
		$this->assertSame( $expected, strtok( $this->more_link( $this->column( $html, 'planned' ) ), '#' ) );
		$this->assertStringContainsString( 'name="redirect_to" value="' . esc_url( add_query_arg( Roadmap::PARAM_PREFIX . 'in-progress', 2, home_url() ) ) . '#mwtfrs-roadmap-', $html );
		$this->assertStringNotContainsString( 'unrelated', $html );
	}

	public function test_show_more_keeps_the_filters_of_a_board_on_the_same_page(): void {
		for ( $i = 1; $i <= 11; $i++ ) {
			$this->request( 'Planned ' . $i, 'planned', 0 );
		}
		$_GET = [
			BoardQuery::PARAM_SEARCH => 'dark mode',
			BoardQuery::PARAM_SORT   => BoardQuery::SORT_NEW,
		];

		$link = strtok( $this->more_link( $this->column( $this->render(), 'planned' ) ), '#' );
		wp_parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $args );

		$this->assertSame(
			[
				BoardQuery::PARAM_SEARCH          => 'dark mode',
				BoardQuery::PARAM_SORT            => BoardQuery::SORT_NEW,
				Roadmap::PARAM_PREFIX . 'planned' => '2',
			],
			$args
		);
	}

	public function test_the_script_learns_which_column_grew_and_where_to_read_on(): void {
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->request( 'Planned ' . $i, 'planned', 100 - $i );
		}

		$first = $this->contexts( $this->column( $this->render(), 'planned' ) );
		$this->assertSame( '', $first[0]['focus'] );
		$this->assertMatchesRegularExpression( '/data-wp-key="planned-1"/', $this->column( $this->render(), 'planned' ) );

		$_GET    = [ Roadmap::PARAM_PREFIX . 'planned' => '2' ];
		$planned = $this->column( $this->render(), 'planned' );
		$context = $this->contexts( $planned )[0];

		$this->assertStringContainsString( 'data-wp-key="planned-2"', $planned, 'A new key, so the column is drawn anew.' );
		$this->assertCount( 12, $context['ids'] );
		$this->assertMatchesRegularExpression( '/^mwtfrs-roadmap-\d+-request-' . $context['ids'][10] . '$/', $context['focus'], 'The eleventh request, the first one added.' );
		$this->assertStringContainsString( 'data-wp-key="in-progress-1"', $this->render() );
	}

	public function test_a_roadmap_covers_every_board_unless_one_is_chosen(): void {
		$mobile = $this->board( 'Mobile app' );
		$this->request( 'Dark mode', 'planned', 0 );
		$offline = $this->request( 'Offline mode', 'planned', 0 );
		wp_set_object_terms( $offline, $mobile, BoardTaxonomy::NAME );

		$this->assertSame( [ 'Dark mode', 'Offline mode' ], $this->sorted_titles( $this->render() ) );
		$this->assertSame( [ 'Offline mode' ], $this->sorted_titles( $this->render( [ 'board' => $mobile ] ) ) );
		$this->assertSame( [ 'Dark mode' ], $this->sorted_titles( $this->render( [ 'board' => $this->default_board() ] ) ) );
		$this->assertSame( [ 'Dark mode', 'Offline mode' ], $this->sorted_titles( $this->render( [ 'board' => 999999 ] ) ), 'A board that no longer exists limits nothing.' );
	}

	public function test_each_card_follows_the_voting_rule_of_its_board_and_closed_statuses_take_no_votes(): void {
		$closed = $this->board( 'Closed board' );
		BoardSettings::update( $closed, [ BoardSettings::VOTING => BoardSettings::NOBODY ] );
		$open    = $this->request( 'Dark mode', 'planned', 3 );
		$off     = $this->request( 'Offline mode', 'planned', 2 );
		$shipped = $this->request( 'Two-factor login', 'shipped', 7 );
		wp_set_object_terms( $off, $closed, BoardTaxonomy::NAME );

		$html = $this->render();

		$this->assertStringContainsString( 'name="request_id" value="' . $open . '"', $html );
		$this->assertStringNotContainsString( 'name="request_id" value="' . $off . '"', $html, 'Its board takes no votes.' );
		$this->assertStringNotContainsString( 'name="request_id" value="' . $shipped . '"', $html, 'Shipped is closed for voting.' );
		$this->assertStringNotContainsString( '<form', $this->column( $html, 'shipped' ) );
		$this->assertStringContainsString( '<span class="mwtfrs-vote__count">7</span>', $this->column( $html, 'shipped' ), 'Its votes still show.' );
		$this->assertSame( 2, substr_count( $html, 'mwtfrs-vote--closed' ) );
	}

	public function test_a_card_shows_the_title_as_a_link_its_vote_button_and_categories_and_no_summary_or_status(): void {
		$request  = $this->request(
			'Dark mode',
			'planned',
			4,
			[ 'post_excerpt' => 'Easier on the eyes at night.' ]
		);
		$category = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Appearance',
			]
		);
		wp_set_object_terms( $request, $category, CategoryTaxonomy::NAME );

		$html = $this->column( $this->render(), 'planned' );

		$this->assertStringContainsString( '<h4 class="mwtfrs-request__title"><a href="' . esc_url( get_permalink( $request ) ) . '">Dark mode</a></h4>', $html, 'Below the column headings.' );
		$this->assertStringContainsString( '<span class="mwtfrs-category">Appearance</span>', $html );
		$this->assertStringContainsString( 'votes. Vote for Dark mode', $html );
		$this->assertStringNotContainsString( 'Easier on the eyes', $html );
		$this->assertSame( 1, substr_count( $html, 'class="mwtfrs-status"' ), 'Only in the heading: the column says the status.' );
	}

	public function test_fuller_columns_of_every_board_ask_the_database_no_more_questions(): void {
		global $wpdb;

		$mobile  = $this->board( 'Mobile app' );
		$queries = [];
		foreach ( [ 2, Roadmap::PER_PAGE ] as $shown ) {
			foreach ( [ 'planned', 'in-progress', 'shipped' ] as $status ) {
				while ( count( $this->titles( $this->column( $this->render(), $status ) ) ) < $shown ) {
					$request = $this->request( 'Request ' . wp_generate_password( 8, false ), $status, 3 );
					if ( $request % 2 ) {
						wp_set_object_terms( $request, $mobile, BoardTaxonomy::NAME );
					}
				}
			}

			// As on a page view: nothing in the object cache yet.
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$html   = $this->render();

			$queries[ $shown ] = $wpdb->num_queries - $before;
			$this->assertSame( 3 * $shown, substr_count( $html, 'class="mwtfrs-request__title"' ) );
		}

		$this->assertSame( $queries[2], $queries[ Roadmap::PER_PAGE ], 'The boards of the requests and their voting rules are read together, not one request at a time.' );
	}

	public function test_the_script_asks_for_a_guests_votes_only_where_a_board_lets_guests_vote(): void {
		$this->request( 'Dark mode', 'planned', 0 );
		$this->assertFalse( $this->context_of( $this->render() )['guestVotes'] );

		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$context = $this->context_of( $this->render() );
		$this->assertTrue( $context['guestVotes'] );
		$this->assertCount( 1, $context['ids'], 'One call for the whole roadmap.' );
	}

	public function test_every_guest_gets_the_same_html_so_pages_can_be_cached(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$request                          = $this->request( 'Dark mode', 'planned', 0 );
		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'c3', 16 );
		VoteService::cast( $request );

		$voter_html = $this->render();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$stranger_html = $this->render();

		$this->assertSame( $this->normalize( $stranger_html ), $this->normalize( $voter_html ) );
		$this->assertStringNotContainsString( 'aria-pressed="true"', $voter_html );
		$this->assertStringNotContainsString( '_wpnonce', $voter_html );
		$this->assertStringContainsString( 'name="action" value="' . VoteForm::ACTION . '"', $voter_html );
		$this->assertStringContainsString( '<span class="mwtfrs-vote__count" data-wp-text="context.votes">1</span>', $voter_html );
	}

	public function test_result_of_a_vote_without_javascript_is_shown_on_that_card(): void {
		$voted = $this->request( 'Dark mode', 'planned', 1 );
		$this->request( 'Export to CSV', 'in-progress', 0 );
		$_GET = [
			RequestNotice::PARAM         => 'added',
			RequestNotice::PARAM_REQUEST => (string) $voted,
		];

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'data-wp-text="context.message">Your vote was added.</span>' ) );
		$this->assertStringContainsString( 'data-wp-text="context.message">Your vote was added.</span>', $this->column( $html, 'planned' ) );
	}

	public function test_a_roadmap_has_its_own_ids_and_router_region_and_does_not_become_the_boards_page(): void {
		$request = $this->request( 'Dark mode', 'planned', 0 );
		$page    = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:' . Roadmap::BLOCK . ' /-->' . "\n\n" . '<!-- wp:' . Roadmap::BLOCK . ' /-->',
			]
		);

		$this->go_to( get_permalink( $page ) );
		the_post();
		$content = (string) apply_filters( 'the_content', get_the_content() );

		preg_match_all( '/id="([^"]*-request-' . $request . ')"/', $content, $ids );
		$this->assertCount( 2, array_unique( $ids[1] ), 'Two roadmaps, two ids.' );
		preg_match_all( '/data-wp-router-region="([^"]+)"/', $content, $regions );
		$this->assertCount( 2, array_unique( $regions[1] ) );
		$this->assertSame( '', BoardSettings::page_url( $this->default_board() ) );
	}

	public function test_empty_columns_and_a_roadmap_without_columns_say_so(): void {
		$this->assertStringContainsString( '<p class="mwtfrs-roadmap__empty">No requests here yet.</p>', $this->column( $this->render(), 'planned' ) );

		foreach ( [ 'planned', 'in-progress', 'shipped' ] as $slug ) {
			update_term_meta( $this->status( $slug ), StatusTaxonomy::META_ROADMAP, false );
		}
		$html = $this->render();

		$this->assertStringContainsString( '<p class="mwtfrs-roadmap__empty">Nothing is on the roadmap yet.</p>', $html );
		$this->assertStringNotContainsString( 'mwtfrs-roadmap__column', $html );
	}

	public function test_shortcode_renders_the_roadmap_of_a_board_or_of_every_board(): void {
		$mobile  = $this->board( 'Mobile app' );
		$offline = $this->request( 'Offline mode', 'planned', 0 );
		wp_set_object_terms( $offline, $mobile, BoardTaxonomy::NAME );
		$this->request( 'Dark mode', 'in-progress', 0 );

		$all = do_shortcode( '[' . Blocks::ROADMAP_SHORTCODE . ']' );
		$this->assertMatchesRegularExpression( '/^<div\s+class="mwtfrs-roadmap wp-block-mwtfrs-roadmap"/', $all );
		$this->assertSame( [ 'Dark mode', 'Offline mode' ], $this->sorted_titles( $all ) );
		$this->assertSame( [ 'Offline mode' ], $this->sorted_titles( do_shortcode( '[' . Blocks::ROADMAP_SHORTCODE . ' board="mobile-app"]' ) ) );
		$this->assertSame( [ 'Offline mode' ], $this->sorted_titles( do_shortcode( '[' . Blocks::ROADMAP_SHORTCODE . ' board="' . $mobile . '"]' ) ) );
	}

	public function test_the_roadmap_and_the_board_share_the_vote_store(): void {
		$this->request( 'Dark mode', 'planned', 0 );
		$roadmap = $this->render();

		preg_match_all( '/data-wp-interactive="([^"]+)"/', $roadmap . $this->render_board(), $stores );
		$this->assertSame( [ 'mwtfrs/votes' ], array_values( array_unique( $stores[1] ) ), 'A vote on a card shows wherever else the request is.' );
		$this->assertStringContainsString( 'data-wp-init="callbacks.hydrate"', $roadmap );
		$this->assertStringContainsString( 'data-wp-watch="callbacks.sync"', $this->column( $roadmap, 'planned' ) );
	}

	/**
	 * Renders the roadmap block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render( array $attributes = [] ): string {
		return render_block(
			[
				'blockName'    => Roadmap::BLOCK,
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Element id of a request on a roadmap.
	 *
	 * @param string $html       Roadmap or column HTML.
	 * @param int    $request_id Request ID.
	 */
	private function dom_id( string $html, int $request_id ): string {
		preg_match( '/id="([^"]+-request-' . $request_id . ')"/', $html, $id );

		return $id[1];
	}

	/**
	 * Renders the board block.
	 */
	private function render_board(): string {
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

	/**
	 * Creates a published request in a status, with a stored vote count.
	 *
	 * @param string               $title  Request title.
	 * @param string               $status Status slug.
	 * @param int                  $votes  Vote count.
	 * @param array<string, mixed> $args   Post fields to override.
	 */
	private function request( string $title, string $status, int $votes, array $args = [] ): int {
		$request = $this->create_request( [ 'post_title' => $title ] + $args );
		wp_set_object_terms( $request, $this->status( $status ), StatusTaxonomy::NAME );
		update_post_meta( $request, RequestPostType::META_VOTES, $votes );

		return $request;
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
	 * Names of the columns, in order.
	 *
	 * @param string $html Roadmap HTML.
	 * @return string[]
	 */
	private function columns( string $html ): array {
		preg_match_all( '#<h3 class="mwtfrs-roadmap__heading"[^>]*>\s*<span class="mwtfrs-status">([^<]+)</span>#', $html, $names );

		return $names[1];
	}

	/**
	 * HTML of one column, from its start to the start of the next.
	 *
	 * @param string $html Roadmap HTML.
	 * @param string $slug Status slug of the column.
	 */
	private function column( string $html, string $slug ): string {
		$parts = preg_split( '/(?=<div\s+class="mwtfrs-roadmap__column")/', $html );
		foreach ( (array) $parts as $part ) {
			if ( preg_match( '/data-wp-key="' . preg_quote( $slug, '/' ) . '-\d+"/', (string) $part ) ) {
				return (string) $part;
			}
		}

		return '';
	}

	/**
	 * Titles of the requests in some HTML, in order.
	 *
	 * @param string $html Roadmap or column HTML.
	 * @return string[]
	 */
	private function titles( string $html ): array {
		preg_match_all( '#class="mwtfrs-request__title"><a href="[^"]*">([^<]+)</a>#', $html, $titles );

		return $titles[1];
	}

	/**
	 * Titles of the requests in some HTML, sorted.
	 *
	 * @param string $html Roadmap HTML.
	 * @return string[]
	 */
	private function sorted_titles( string $html ): array {
		$titles = $this->titles( $html );
		sort( $titles );

		return $titles;
	}

	/**
	 * Titles "Planned 1" to "Planned N".
	 *
	 * @param int $from First number.
	 * @param int $to   Last number.
	 * @return string[]
	 */
	private function numbered( int $from, int $to ): array {
		return array_map(
			static function ( int $number ): string {
				return 'Planned ' . $number;
			},
			range( $from, $to )
		);
	}

	/**
	 * Address of a column's "Show more" link, decoded, or ''.
	 *
	 * @param string $column Column HTML.
	 */
	private function more_link( string $column ): string {
		return preg_match( '#<p class="mwtfrs-roadmap__more">\s*<a href="([^"]+)"#', $column, $link ) ? html_entity_decode( $link[1] ) : '';
	}

	/**
	 * The contexts in some HTML, in order.
	 *
	 * @param string $html Roadmap or column HTML.
	 * @return array<int, array<string, mixed>>
	 */
	private function contexts( string $html ): array {
		preg_match_all( "/data-wp-context='([^']*)'/", $html, $contexts );

		return array_map(
			static function ( string $json ): array {
				return (array) json_decode( $json, true );
			},
			$contexts[1]
		);
	}

	/**
	 * Returns the context the roadmap gives its script.
	 *
	 * @param string $html Roadmap HTML.
	 * @return array<string, mixed>
	 */
	private function context_of( string $html ): array {
		return $this->contexts( $html )[0];
	}

	/**
	 * Removes what legitimately differs between two renders: generated IDs.
	 *
	 * @param string $html Roadmap HTML.
	 */
	private function normalize( string $html ): string {
		return (string) preg_replace( '/mwtfrs-roadmap-\d+/', 'mwtfrs-roadmap-N', $html );
	}
}
