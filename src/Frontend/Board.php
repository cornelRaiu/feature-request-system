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
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Voting\VoteCount;
use stdClass;
use WP;
use WP_Post;
use WP_Query;
use WP_Term;

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
	 * Block name, also the Interactivity API store namespace.
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
		$board = self::board( (int) ( $attributes['board'] ?? 0 ) );
		if ( ! $board ) {
			return '';
		}

		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $attributes['perPage'] ?? self::DEFAULT_PER_PAGE ) ) );
		$filters  = BoardQuery::filters_from_request();
		$query    = new WP_Query( BoardQuery::args( $board->term_id, $filters, $per_page ) );
		$voting   = BoardSettings::get( $board->term_id )[ BoardSettings::VOTING ];
		$notice   = VoteForm::notice_from_request();
		$page_url = self::page_url();
		$current  = add_query_arg( self::filter_args( $filters, true ), $page_url );

		self::provide_state();

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = self::item( $post, $voting, $notice );
			}
		}

		return Template::render(
			'board',
			[
				'namespace'          => self::BLOCK,
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
				'current_url'        => $current,
				'vote_url'           => admin_url( 'admin-post.php' ),
				'vote_action'        => VoteForm::ACTION,
				'vote_nonce'         => is_user_logged_in() ? wp_create_nonce( VoteForm::ACTION ) : '',
				'login_url'          => wp_login_url( $current ),
				'pagination'         => self::pagination( $query, $filters['page'], add_query_arg( self::filter_args( $filters, false ), $page_url ) ),
				'empty_message'      => $items ? '' : self::empty_message( $filters, $board->term_id ),
			]
		);
	}

	/**
	 * HTML id of a request's row, used as the anchor to return to after a vote.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function item_id( int $request_id ): string {
		return 'mwtfrs-request-' . $request_id;
	}

	/**
	 * Returns the board to show: the chosen one, or the default board if it no longer exists.
	 *
	 * @param int $board_id Board term ID, or 0 for the default board.
	 */
	private static function board( int $board_id ): ?WP_Term {
		foreach ( [ $board_id, RequestTerms::default_term( BoardTaxonomy::NAME ) ] as $id ) {
			$term = $id ? get_term( $id, BoardTaxonomy::NAME ) : null;
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Prepares one request for the template.
	 *
	 * @param WP_Post                                                $post   Request.
	 * @param string                                                 $voting Board voting rule, a BoardSettings value.
	 * @param array{request: int, message: string, login: bool}|null $notice Notice from a vote without JavaScript.
	 * @return array<string, mixed>
	 */
	private static function item( WP_Post $post, string $voting, ?array $notice ): array {
		$status     = RequestTerms::term_of( $post->ID, StatusTaxonomy::NAME );
		$categories = get_the_terms( $post->ID, CategoryTaxonomy::NAME );
		$summary    = '' !== $post->post_excerpt ? $post->post_excerpt : strip_shortcodes( $post->post_content );
		$votes      = VoteCount::get( $post->ID );
		$notice     = $notice && $notice['request'] === $post->ID ? $notice : null;

		return [
			'id'         => $post->ID,
			'dom_id'     => self::item_id( $post->ID ),
			'title'      => get_the_title( $post ),
			'url'        => (string) get_permalink( $post ),
			'excerpt'    => wp_trim_words( wp_strip_all_tags( $summary ), 30 ),
			'votes'      => $votes,
			'status'     => $status ? [
				'name'  => $status->name,
				'color' => (string) get_term_meta( $status->term_id, StatusTaxonomy::META_COLOR, true ),
			] : null,
			'categories' => is_array( $categories ) ? wp_list_pluck( $categories, 'name' ) : [],
			'votable'    => BoardSettings::NOBODY !== $voting && ! ( $status && StatusTaxonomy::is_closed( $status ) ),
			'context'    => [
				'id'         => $post->ID,
				'votes'      => $votes,
				'voted'      => false,
				'busy'       => false,
				'message'    => $notice ? $notice['message'] : '',
				'needsLogin' => $notice ? $notice['login'] : false,
			],
		];
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
	 * Shares the settings the vote script needs. Holds nothing about the visitor except,
	 * for logged-in users, the REST nonce WordPress requires.
	 */
	private static function provide_state(): void {
		wp_interactivity_state(
			self::BLOCK,
			[
				'restUrl'      => rest_url( RestApi::NAMESPACE . '/' ),
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'voted'        => new stdClass(),
				'announcement' => '',
				'strings'      => [
					'added'   => __( 'Vote added.', 'feature-request-system' ),
					'removed' => __( 'Vote removed.', 'feature-request-system' ),
					'error'   => __( 'Your vote could not be saved. Check your connection and try again.', 'feature-request-system' ),
				],
			]
		);
	}

	/**
	 * URL of the page showing the board, without the board's own parameters.
	 */
	private static function page_url(): string {
		if ( is_singular() ) {
			return (string) get_permalink( get_queried_object_id() );
		}

		global $wp;

		return home_url( $wp instanceof WP ? $wp->request : '' );
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
