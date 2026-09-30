<?php
/**
 * Board URL filter tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Frontend\BoardQuery;

/**
 * Filters come from the query string, so they must be sanitized before they reach a query.
 */
final class BoardQueryTest extends TestCase {

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	public function test_without_filters_the_board_shows_the_first_page_by_most_votes(): void {
		$this->assertSame(
			[
				'search'   => '',
				'status'   => '',
				'category' => '',
				'sort'     => BoardQuery::SORT_TOP,
				'page'     => 1,
			],
			BoardQuery::filters_from_request()
		);
	}

	public function test_filters_are_sanitized(): void {
		$_GET = [
			BoardQuery::PARAM_SEARCH   => '  dark <em>mode</em> ',
			BoardQuery::PARAM_STATUS   => 'In Progress',
			BoardQuery::PARAM_CATEGORY => '<b>Mobile</b>',
			BoardQuery::PARAM_SORT     => 'random',
			BoardQuery::PARAM_PAGE     => 'two',
		];

		$this->assertSame(
			[
				'search'   => 'dark mode',
				'status'   => 'in-progress',
				'category' => 'mobile',
				'sort'     => BoardQuery::SORT_TOP,
				'page'     => 1,
			],
			BoardQuery::filters_from_request()
		);
	}

	public function test_array_values_in_the_query_string_are_ignored(): void {
		$_GET = [
			BoardQuery::PARAM_SEARCH => [ 'x' ],
			BoardQuery::PARAM_SORT   => [ BoardQuery::SORT_NEW ],
		];

		$filters = BoardQuery::filters_from_request();

		$this->assertSame( '', $filters['search'] );
		$this->assertSame( BoardQuery::SORT_TOP, $filters['sort'] );
	}
}
