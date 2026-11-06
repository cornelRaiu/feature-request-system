<?php
/**
 * Tests of the board's filter switches and of the markup that live filtering uses.
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
use FeatureRequestSystem\Frontend\VoteStore;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use ReflectionProperty;

/**
 * Renders the real board block and its shortcode, the way a page does.
 */
final class BoardFiltersTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );

		$category = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Mobile',
			]
		);
		$planned  = $this->request( 'Dark mode', 3 );
		$started  = $this->request( 'Export to CSV', 2 );
		$this->request( 'Calendar view', 1 );
		wp_set_object_terms( $planned, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $planned, $category, CategoryTaxonomy::NAME );
		wp_set_object_terms( $started, $this->status( 'in-progress' ), StatusTaxonomy::NAME );
	}

	public function tear_down(): void {
		$_GET = [];
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_a_board_shows_every_control_unless_told_otherwise(): void {
		$form = $this->form( $this->render() );

		foreach ( array_keys( Board::CONTROLS ) as $control ) {
			$this->assertStringContainsString( 'name="' . $this->param( $control ) . '"', $form, "The {$control} control is shown." );
		}
	}

	public function test_each_switch_turns_off_its_control_only(): void {
		foreach ( Board::CONTROLS as $control => $attribute ) {
			$form = $this->form( $this->render( [ $attribute => false ] ) );

			$this->assertStringNotContainsString( 'name="' . $this->param( $control ) . '"', $form, "{$attribute} turns off the {$control} control." );
			foreach ( array_diff_key( Board::CONTROLS, [ $control => true ] ) as $other => $unused ) {
				$this->assertStringContainsString( 'name="' . $this->param( $other ) . '"', $form, "{$attribute} leaves the {$other} control." );
			}
		}
	}

	public function test_with_every_control_off_no_form_is_printed_and_the_requests_still_are(): void {
		$html = $this->render( array_fill_keys( array_values( Board::CONTROLS ), false ) + [ 'perPage' => 2 ] );

		$this->assertStringNotContainsString( '<form class="mwtfrs-board__filters"', $html );
		$this->assertStringNotContainsString( 'mwtfrs-board__filters', $html );
		$this->assertStringNotContainsString( 'Show requests', $html );
		$this->assertStringContainsString( '>Dark mode</a>', $html );
		$this->assertStringContainsString( BoardQuery::PARAM_PAGE . '=2', $html, 'The pages are still there.' );
	}

	public function test_a_form_with_only_the_category_control_and_no_categories_is_not_printed(): void {
		$switches = [
			'showSearch' => false,
			'showStatus' => false,
			'showSort'   => false,
		];
		$this->assertStringContainsString( 'name="' . BoardQuery::PARAM_CATEGORY . '"', $this->form( $this->render( $switches ) ) );

		BoardSettings::update( $this->default_board(), [ BoardSettings::CATEGORIES => [ self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] ) ] ] );

		$this->assertStringNotContainsString( 'mwtfrs-board__filters', $this->render( $switches ), 'The board has no category with requests to choose.' );
	}

	public function test_the_shortcode_takes_yes_and_no_for_each_control(): void {
		$shortcode = '[' . Blocks::SHORTCODE . ' search="no" status_filter="no" category_filter="yes" sort_filter="no"]';

		$form = $this->form( do_shortcode( $shortcode ) );
		$this->assertStringContainsString( 'name="' . BoardQuery::PARAM_CATEGORY . '"', $form );
		foreach ( [ 'search', 'status', 'sort' ] as $control ) {
			$this->assertStringNotContainsString( 'name="' . $this->param( $control ) . '"', $form, $control );
		}

		$html = do_shortcode( '[' . Blocks::SHORTCODE . ' search="no" status_filter="NO" category_filter="no" sort_filter="no"]' );
		$this->assertStringNotContainsString( 'mwtfrs-board__filters', $html );
		$this->assertStringContainsString( '>Dark mode</a>', $html );

		$this->assertSame( 4, substr_count( $this->form( do_shortcode( '[' . Blocks::SHORTCODE . ']' ) ), '<label for=' ), 'Without them, every control is there.' );
	}

	public function test_a_hidden_control_still_filters_from_the_url_and_the_form_keeps_its_value(): void {
		$_GET = [
			BoardQuery::PARAM_STATUS => 'planned',
			BoardQuery::PARAM_SORT   => BoardQuery::SORT_NEW,
		];

		$html = $this->render(
			[
				'showStatus' => false,
				'showSort'   => false,
			]
		);

		$this->assertStringContainsString( '>Dark mode</a>', $html );
		$this->assertStringNotContainsString( '>Export to CSV</a>', $html );
		$form = $this->form( $html );
		$this->assertStringContainsString( '<input type="hidden" name="' . BoardQuery::PARAM_STATUS . '" value="planned">', $form, 'A search keeps the status of the link.' );
		$this->assertStringContainsString( '<input type="hidden" name="' . BoardQuery::PARAM_SORT . '" value="' . BoardQuery::SORT_NEW . '">', $form );

		$_GET = [ BoardQuery::PARAM_SEARCH => 'CSV' ];
		$html = $this->render( [ 'showSearch' => false ] );
		$this->assertStringContainsString( '>Export to CSV</a>', $html );
		$this->assertStringNotContainsString( '>Dark mode</a>', $html );
		$this->assertStringContainsString( '<input type="hidden" name="' . BoardQuery::PARAM_SEARCH . '" value="CSV">', $this->form( $html ) );

		$_GET = [];
		$this->assertStringNotContainsString( 'type="hidden" name="fr-', $this->render( [ 'showSort' => false ] ), 'The default order needs no field.' );
	}

	public function test_the_form_applies_as_it_changes_once_the_script_runs(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<form\s+class="mwtfrs-board__filters"[^>]*data-wp-interactive="' . preg_quote( VoteStore::NAME, '#' ) . '"#', $html );
		$this->assertMatchesRegularExpression( '#<form[^>]*data-wp-on--change="actions.filter"[^>]*>#', $html );
		$this->assertMatchesRegularExpression( '#<form[^>]*data-wp-on--submit="actions.filter"[^>]*>#', $html );
		$this->assertMatchesRegularExpression( '#<input type="search"[^>]*data-wp-on--input="actions.search"#', $html );
		$this->assertMatchesRegularExpression( '#<p class="mwtfrs-board__field mwtfrs-board__field--submit" data-wp-bind--hidden="context.live">\s*<button type="submit"#', $html, 'Without JavaScript, the button is there.' );
		$this->assertStringContainsString( '"live":false', $this->form( $html ) );
	}

	public function test_the_results_are_a_region_of_their_own_with_the_same_name_on_every_load(): void {
		$this->new_page_load();
		$first = $this->render();
		$this->new_page_load();
		$again = $this->render( [], [ BoardQuery::PARAM_STATUS => 'planned' ] );

		preg_match( '#data-wp-router-region="([^"]+)"#', $first, $region );
		$this->assertNotEmpty( $region );
		$this->assertStringContainsString( 'data-wp-router-region="' . $region[1] . '"', $again, 'The router finds the results again in the page it fetches.' );
		$this->assertStringContainsString( '"region":"' . $region[1] . '"', $this->form( $first ), 'The form knows which results are its own.' );
		$this->assertStringContainsString( 'id="' . $region[1] . '-summary" aria-live="polite"', $first );
		$this->assertMatchesRegularExpression( '#<div class="mwtfrs-board__results" data-wp-interactive="' . preg_quote( VoteStore::NAME, '#' ) . '" data-wp-router-region="#', $first, 'A region is an interactive element of its own.' );
		$this->assertStringNotContainsString( 'data-wp-interactive', $this->wrapper( $first ), 'The board around it is not, so the region is not inside another.' );

		$this->assertNotSame( '', $this->region( $this->render( array_fill_keys( array_values( Board::CONTROLS ), false ) ) ), 'Its pages change without a form too.' );
		$this->assertNotSame( $region[1], $this->region( $this->render() ), 'A second board on the page has its own.' );
	}

	public function test_each_set_of_results_has_its_own_key_and_says_how_many_it_shows(): void {
		$all     = $this->results( $this->render( [ 'perPage' => 2 ] ) );
		$planned = $this->results( $this->render( [ 'perPage' => 2 ], [ BoardQuery::PARAM_STATUS => 'planned' ] ) );
		$second  = $this->results( $this->render( [ 'perPage' => 2 ], [ BoardQuery::PARAM_PAGE => '2' ] ) );
		$none    = $this->results( $this->render( [ 'perPage' => 2 ], [ BoardQuery::PARAM_SEARCH => 'nothing like it' ] ) );

		$this->assertCount( 4, array_unique( array_column( [ $all, $planned, $second, $none ], 'key' ) ), 'New results start afresh, so their votes are fetched.' );
		$this->assertSame( $all['key'], $this->results( $this->render( [ 'perPage' => 2 ], [] ) )['key'], 'The same results keep their key.' );

		$this->assertSame( 'Showing 2 of 3 requests.', $all['context']['summary'] );
		$this->assertSame( 'Showing 1 request.', $planned['context']['summary'] );
		$this->assertSame( 'Showing 1 of 3 requests.', $second['context']['summary'] );
		$this->assertSame( 'No requests match these filters.', $none['context']['summary'] );

		$this->assertCount( 1, $planned['context']['ids'], 'The results ask for the votes of their own requests.' );
		$this->assertSame( '', $planned['context']['announcement'] );
		$this->assertStringContainsString( 'data-wp-init="callbacks.showResults"', $planned['html'] );
	}

	public function test_page_links_stay_links_and_turn_pages_in_place_with_the_script(): void {
		$html = $this->render( [ 'perPage' => 2 ], [ BoardQuery::PARAM_STATUS => BoardQuery::STATUS_ALL ] );

		$this->assertMatchesRegularExpression( '#<nav class="mwtfrs-board__pagination"[^>]*data-wp-on--click="actions.turnPage"#', $html );
		$this->assertMatchesRegularExpression( '#<a class="page-numbers" href="[^"]*' . BoardQuery::PARAM_STATUS . '=' . BoardQuery::STATUS_ALL . '[^"]*' . BoardQuery::PARAM_PAGE . '=2[^"]*"#', $html );
	}

	public function test_filtered_results_are_the_same_for_every_guest(): void {
		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
		$_COOKIE[ VoterIdentity::COOKIE ] = str_repeat( 'c3', 16 );
		$voted                            = get_posts(
			[
				'post_type' => RequestPostType::NAME,
				'title'     => 'Dark mode',
				'fields'    => 'ids',
			]
		)[0];
		VoteService::cast( $voted );
		$filters = [ BoardQuery::PARAM_STATUS => 'planned' ];

		$this->new_page_load();
		$voter = $this->render( [], $filters );
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$this->new_page_load();
		$stranger = $this->render( [], $filters );

		$this->assertSame( $this->normalize( $voter ), $this->normalize( $stranger ) );
		$this->assertStringNotContainsString( 'aria-pressed="true"', $voter );
		$this->assertStringNotContainsString( '_wpnonce', $voter );
	}

	public function test_the_list_takes_no_notice_of_the_switches(): void {
		$list = [
			'compact' => true,
			'perPage' => 3,
		];

		$plain    = $this->render( $list );
		$switched = $this->render( $list + array_fill_keys( array_values( Board::CONTROLS ), false ) );

		$this->assertSame( preg_replace( '/mwtfrs-list-\d+/', 'mwtfrs-list-N', $plain ), preg_replace( '/mwtfrs-list-\d+/', 'mwtfrs-list-N', $switched ) );
		$this->assertStringContainsString( '>Dark mode</a>', $switched );
		$this->assertStringNotContainsString( 'data-wp-router-region', $plain, 'The list has no pages to turn.' );
	}

	public function test_a_theme_copy_of_the_board_template_from_1_0_still_works(): void {
		global $wp_stylesheet_path, $wp_template_path;
		wp_set_template_globals();
		$theme              = [ $wp_stylesheet_path, $wp_template_path ];
		$wp_stylesheet_path = dirname( __DIR__ ) . '/Fixtures/theme-1.0';
		$wp_template_path   = $wp_stylesheet_path;

		try {
			$html = $this->render( [ 'perPage' => 2 ], [ BoardQuery::PARAM_STATUS => BoardQuery::STATUS_ALL ] );
		} finally {
			list( $wp_stylesheet_path, $wp_template_path ) = $theme;
		}

		$this->assertMatchesRegularExpression( "#^<div[^>]*data-wp-interactive=\"" . preg_quote( VoteStore::NAME, '#' ) . "\"\\s+data-wp-context='\\{\"ids\":\\[\\d+,\\d+\\]#", $html, 'The board is interactive as in 1.0, with the ids of its requests.' );
		$this->assertStringContainsString( 'data-wp-init="callbacks.hydrate"', $html );
		$this->assertStringContainsString( '<option value="' . BoardQuery::STATUS_ALL . '"  selected=\'selected\'', $html );
		$this->assertStringContainsString( 'Show requests', $html );
		$this->assertStringContainsString( BoardQuery::PARAM_PAGE . '=2', $html );
		$this->assertStringNotContainsString( 'data-wp-router-region', $html, 'Live filtering needs the new template.' );
	}

	/**
	 * Renders the board block.
	 *
	 * @param array<string, mixed>  $attributes Block attributes.
	 * @param array<string, string> $get        URL parameters, or the current ones when not given.
	 */
	private function render( array $attributes = [], ?array $get = null ): string {
		if ( null !== $get ) {
			$_GET = $get;
		}

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
	 * Starts counting the boards on a page again, as a new page load does.
	 */
	private function new_page_load(): void {
		$rendered = new ReflectionProperty( Board::class, 'rendered' );
		$rendered->setAccessible( true );
		$rendered->setValue( null, 0 );
	}

	/**
	 * Creates a published request with a stored vote count.
	 *
	 * @param string $title Request title.
	 * @param int    $votes Vote count.
	 */
	private function request( string $title, int $votes ): int {
		$request = $this->create_request( [ 'post_title' => $title ] );
		update_post_meta( $request, RequestPostType::META_VOTES, $votes );

		return $request;
	}

	/**
	 * URL parameter of a control.
	 *
	 * @param string $control Control, a key of Board::CONTROLS.
	 */
	private function param( string $control ): string {
		$params = [
			'search'   => BoardQuery::PARAM_SEARCH,
			'status'   => BoardQuery::PARAM_STATUS,
			'category' => BoardQuery::PARAM_CATEGORY,
			'sort'     => BoardQuery::PARAM_SORT,
		];

		return $params[ $control ];
	}

	/**
	 * The filter form of a board, or '' when it has none.
	 *
	 * @param string $html Board HTML.
	 */
	private function form( string $html ): string {
		return preg_match( '#<form\s+class="mwtfrs-board__filters".*?</form>#s', $html, $form ) ? $form[0] : '';
	}

	/**
	 * The opening tag of the board's outer element.
	 *
	 * @param string $html Board HTML.
	 */
	private function wrapper( string $html ): string {
		return preg_match( '#^<div[^>]*>#', $html, $tag ) ? $tag[0] : '';
	}

	/**
	 * The name of a board's results region.
	 *
	 * @param string $html Board HTML.
	 */
	private function region( string $html ): string {
		return preg_match( '#data-wp-router-region="([^"]+)"#', $html, $region ) ? $region[1] : '';
	}

	/**
	 * The element that holds one set of results: its key, its context and its markup.
	 *
	 * @param string $html Board HTML.
	 * @return array{key: string, context: array<string, mixed>, html: string}
	 */
	private function results( string $html ): array {
		preg_match( "#<div\\s+class=\"mwtfrs-board__page\"\\s+data-wp-key=\"([^\"]+)\"\\s+data-wp-context='([^']*)'[^>]*>#", $html, $match );
		$this->assertNotEmpty( $match, 'The results have a key and a context.' );

		return [
			'key'     => $match[1],
			'context' => (array) json_decode( $match[2], true ),
			'html'    => $match[0],
		];
	}

	/**
	 * Removes what legitimately differs between two renders: generated IDs.
	 *
	 * @param string $html Board HTML.
	 */
	private function normalize( string $html ): string {
		return (string) preg_replace( '/mwtfrs-board-\d+/', 'mwtfrs-board-N', $html );
	}
}
