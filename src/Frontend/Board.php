<?php
/**
 * The feature request board.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares a board's data and renders it with the board template.
 *
 * The HTML is the same for every visitor with the same URL, so page caches can serve it.
 * The only exception is the nonces logged-in users need, and caches do not serve
 * logged-in users. Which requests the visitor voted for is fetched after the page loads.
 */
final class Board {

	/**
	 * Block name.
	 */
	public const BLOCK = 'mwtfrs/board';

	/**
	 * Requests per page when the block does not set it.
	 */
	private const DEFAULT_PER_PAGE = 20;

	/**
	 * Most requests one page may show.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Renders a board. Called by the block's render.php; the shortcode renders the block.
	 *
	 * @param array<string, mixed> $attributes         Block attributes: board (term ID, 0 for the default board) and perPage.
	 * @param string               $wrapper_attributes Attributes from get_block_wrapper_attributes().
	 */
	public static function render( array $attributes, string $wrapper_attributes ): string {
		$board = BoardTaxonomy::resolve( (int) ( $attributes['board'] ?? 0 ) );
		if ( ! $board ) {
			return '';
		}

		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $attributes['perPage'] ?? self::DEFAULT_PER_PAGE ) ) );
		$filters  = BoardQuery::filters_from_request();
		$query    = new WP_Query( BoardQuery::args( $board->term_id, $filters, $per_page ) );
		$voting   = BoardSettings::voting( $board->term_id );
		$notice   = VoteForm::notice_from_request();
		$page_url = CurrentPage::url();
		$current  = add_query_arg( self::filter_args( $filters, true ), $page_url );

		VoteStore::provide();
		self::remember_page( $board->term_id );

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = RequestItem::prepare( $post, $voting, $notice );
			}
		}

		return Template::render(
			'board',
			[
				'namespace'          => VoteStore::NAME,
				'wrapper_attributes' => $wrapper_attributes,
				'context'            => [ 'ids' => wp_list_pluck( $items, 'id' ) ],
				'id_prefix'          => wp_unique_id( 'mwtfrs-board-' ),
				'items'              => $items,
				'filters'            => $filters,
				'params'             => [
					'search'   => BoardQuery::PARAM_SEARCH,
					'status'   => BoardQuery::PARAM_STATUS,
					'category' => BoardQuery::PARAM_CATEGORY,
					'sort'     => BoardQuery::PARAM_SORT,
				],
				'status_all'         => BoardQuery::STATUS_ALL,
				'statuses'           => self::term_options( StatusTaxonomy::NAME ),
				'categories'         => self::term_options( CategoryTaxonomy::NAME ),
				'sorts'              => [
					BoardQuery::SORT_TOP => __( 'Most votes', 'feature-request-system' ),
					BoardQuery::SORT_NEW => __( 'Newest', 'feature-request-system' ),
				],
				'page_url'           => $page_url,
				'hidden_fields'      => self::query_args_of( $page_url ),
				'vote'               => VoteForm::template_args( $current ),
				'login_url'          => wp_login_url( $current ),
				'pagination'         => self::pagination( $query, $filters['page'], add_query_arg( self::filter_args( $filters, false ), $page_url ) ),
				'empty_message'      => $items ? '' : self::empty_message( $filters, $board->term_id ),
			]
		);
	}

	/**
	 * Notes the page a board is shown on, so that its requests can link back to it.
	 *
	 * The board is a block that can sit on any page, so the plugin learns the page the
	 * first time the board is shown on a published one. A page chosen in the board's
	 * settings is kept for as long as it stays published.
	 *
	 * @param int $board_id Board term ID.
	 */
	private static function remember_page( int $board_id ): void {
		$page_id = get_queried_object_id();

		if ( is_singular() && ! is_singular( RequestPostType::NAME ) && 'publish' === get_post_status( $page_id ) && '' === BoardSettings::page_url( $board_id ) ) {
			BoardSettings::update( $board_id, [ BoardSettings::PAGE => $page_id ] );
		}
	}

	/**
	 * Explains why the list is empty.
	 *
	 * @param array{search: string, status: string, category: string, sort: string, page: int} $filters  Current filters.
	 * @param int                                                                              $board_id Board term ID.
	 */
	private static function empty_message( array $filters, int $board_id ): string {
		if ( self::filter_args( $filters, false ) ) {
			return __( 'No requests match these filters.', 'feature-request-system' );
		}

		$any_request = get_posts(
			[
				'post_type'      => RequestPostType::NAME,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Runs only when the board is empty.
					[
						'taxonomy' => BoardTaxonomy::NAME,
						'field'    => 'term_id',
						'terms'    => $board_id,
					],
				],
			]
		);

		return $any_request
			? __( 'No requests are open for voting right now.', 'feature-request-system' )
			: __( 'No requests yet.', 'feature-request-system' );
	}

	/**
	 * URL parameters for the filters that differ from the defaults.
	 *
	 * @param array{search: string, status: string, category: string, sort: string, page: int} $filters   Current filters.
	 * @param bool                                                                             $with_page Whether to include the page number.
	 * @return array<string, string|int>
	 */
	private static function filter_args( array $filters, bool $with_page ): array {
		$args = array_filter(
			[
				BoardQuery::PARAM_SEARCH   => $filters['search'],
				BoardQuery::PARAM_STATUS   => $filters['status'],
				BoardQuery::PARAM_CATEGORY => $filters['category'],
				BoardQuery::PARAM_SORT     => BoardQuery::SORT_TOP === $filters['sort'] ? '' : $filters['sort'],
			]
		);

		if ( $with_page && $filters['page'] > 1 ) {
			$args[ BoardQuery::PARAM_PAGE ] = $filters['page'];
		}

		return array_map( 'rawurlencode', $args );
	}

	/**
	 * Query parameters already in the page URL, such as page_id with plain permalinks.
	 * The filter form repeats them, because submitting a GET form replaces the query string.
	 *
	 * @param string $url Page URL.
	 * @return array<string, string>
	 */
	private static function query_args_of( string $url ): array {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return [];
		}

		wp_parse_str( $query, $args );

		return array_filter( $args, 'is_string' );
	}

	/**
	 * Terms for a filter dropdown, keyed by slug. Statuses keep their workflow order.
	 *
	 * @param string $taxonomy Status or category taxonomy.
	 * @return array<string, string>
	 */
	private static function term_options( string $taxonomy ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => CategoryTaxonomy::NAME === $taxonomy,
			]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}

		if ( StatusTaxonomy::NAME === $taxonomy ) {
			$terms = StatusTaxonomy::sort( $terms );
		}

		return wp_list_pluck( $terms, 'name', 'slug' );
	}

	/**
	 * Page links, or '' when everything fits on one page.
	 *
	 * @param WP_Query $query   Board query.
	 * @param int      $current Current page.
	 * @param string   $base    Board URL with the current filters.
	 */
	private static function pagination( WP_Query $query, int $current, string $base ): string {
		if ( $query->max_num_pages < 2 ) {
			return '';
		}

		return (string) paginate_links(
			[
				'base'      => add_query_arg( BoardQuery::PARAM_PAGE, '%#%', $base ),
				'format'    => '',
				'current'   => $current,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => __( 'Previous', 'feature-request-system' ),
				'next_text' => __( 'Next', 'feature-request-system' ),
			]
		);
	}
}
