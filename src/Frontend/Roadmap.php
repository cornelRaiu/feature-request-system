<?php
/**
 * The feature request roadmap.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_Post;
use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Feature Request Roadmap block: a column for each status that is shown on
 * the roadmap, in workflow order, with the requests in that status, most votes first.
 *
 * Each column shows a few requests and a "Show more" link that shows more of them. The
 * link carries the number of pages to show in the URL, so it works without JavaScript,
 * and with JavaScript the Interactivity API's router fetches the same URL and swaps the
 * roadmap in place. Like the board, the HTML is the same for every visitor with the same URL.
 */
final class Roadmap {

	/**
	 * Block name.
	 */
	public const BLOCK = 'mwtfrs/roadmap';

	/**
	 * Start of the URL parameter that says how many pages of a column to show, followed by
	 * the status slug, as in fr-roadmap-planned=2.
	 */
	public const PARAM_PREFIX = 'fr-roadmap-';

	/**
	 * Requests a column shows, and how many more each "Show more" adds.
	 */
	public const PER_PAGE = 10;

	/**
	 * Most pages a column shows, so that a URL cannot make one page list every request.
	 */
	public const MAX_PAGES = 10;

	/**
	 * Renders a roadmap. Called by the block's render.php; the shortcode renders the block.
	 *
	 * @param array<string, mixed> $attributes         Block attributes: board (term ID, 0 for every board).
	 * @param string               $wrapper_attributes Attributes from get_block_wrapper_attributes().
	 */
	public static function render( array $attributes, string $wrapper_attributes ): string {
		$board    = self::board( (int) ( $attributes['board'] ?? 0 ) );
		$statuses = StatusTaxonomy::on_roadmap();
		$pages    = self::pages_from_request( $statuses );
		$page_url = CurrentPage::url();
		$current  = add_query_arg( self::page_args( $pages ), $page_url );
		$notice   = RequestNotice::from_request();
		$instance = wp_unique_id( 'mwtfrs-roadmap-' );

		VoteStore::provide();

		$columns = [];
		$all     = [];
		foreach ( $statuses as $status ) {
			$column    = self::column( $status, $board, $pages, $page_url, $notice, $instance );
			$all       = array_merge( $all, $column['items'] );
			$columns[] = $column;
		}

		return Template::render(
			'roadmap',
			[
				'namespace'          => VoteStore::NAME,
				'wrapper_attributes' => $wrapper_attributes,
				'context'            => VoteStore::context( $all ),
				'region'             => $instance,
				'columns'            => $columns,
				// Below the column headings.
				'title_tag'          => 'h4',
				// Leaves out the summary; the categories stay.
				'compact'            => false,
				'vote'               => VoteForm::template_args( $current ),
				'login_url'          => wp_login_url( $current ),
			]
		);
	}

	/**
	 * Prepares one column: a status and the requests in it that the column shows.
	 *
	 * @param WP_Term                                                $status   Status of the column.
	 * @param WP_Term|null                                           $board    Board to show, or null for every board.
	 * @param array<string, int>                                     $pages    Pages each column shows, by status slug.
	 * @param string                                                 $page_url Page the roadmap is on.
	 * @param array{request: int, message: string, login: bool}|null $notice   Notice from a vote without JavaScript.
	 * @param string                                                 $instance Unique prefix of this roadmap.
	 * @return array{slug: string, name: string, color: string, heading_id: string, key: string, total: int, items: array<int, array<string, mixed>>, more_url: string, context: array{ids: int[], focus: string}}
	 */
	private static function column( WP_Term $status, ?WP_Term $board, array $pages, string $page_url, ?array $notice, string $instance ): array {
		$shown   = $pages[ $status->slug ];
		$filters = [
			'search'   => '',
			'status'   => $status->slug,
			'category' => '',
			'sort'     => BoardQuery::SORT_TOP,
			'page'     => 1,
		];
		$limit   = $shown * self::PER_PAGE;
		// One more than the column shows: the request that "Show more" leads to.
		$query = new WP_Query( BoardQuery::args( $board ? $board->term_id : 0, $filters, $limit + 1 ) );

		$posts = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}

		$items = [];
		foreach ( array_slice( $posts, 0, $limit ) as $post ) {
			// A roadmap of every board follows each request's own board.
			$of   = $board ? $board : RequestTerms::term_of( $post->ID, BoardTaxonomy::NAME );
			$item = RequestItem::prepare( $post, BoardSettings::voting( $of ? $of->term_id : 0 ), $notice, $instance );
			// The column says the status, and a card is short: its title, its vote button and its categories.
			$item['status']  = null;
			$item['excerpt'] = '';
			$items[]         = $item;
		}

		$next = isset( $posts[ $limit ] ) && $shown < self::MAX_PAGES ? $posts[ $limit ] : null;
		// After "Show more", the visitor reads on from the first request it added.
		$first = $shown > 1 && isset( $items[ $limit - self::PER_PAGE ] ) ? $items[ $limit - self::PER_PAGE ]['dom_id'] : '';

		return [
			'slug'       => $status->slug,
			'name'       => $status->name,
			'color'      => StatusTaxonomy::color( $status ),
			'heading_id' => $instance . '-' . $status->slug,
			// Changes when the column shows more, so the script treats it as a new column.
			'key'        => $status->slug . '-' . $shown,
			'total'      => (int) $query->found_posts,
			'items'      => $items,
			'more_url'   => $next ? add_query_arg( self::page_args( [ $status->slug => $shown + 1 ] + $pages ), $page_url ) . '#' . RequestItem::dom_id( $next->ID, $instance ) : '',
			'context'    => [
				'ids'   => array_values( wp_list_pluck( $items, 'id' ) ),
				'focus' => $first,
			],
		];
	}

	/**
	 * Reads from the URL how many pages each column shows: at least one, and no more than MAX_PAGES.
	 *
	 * @param WP_Term[] $statuses Statuses of the columns.
	 * @return array<string, int> Pages by status slug.
	 */
	private static function pages_from_request( array $statuses ): array {
		$pages = [];
		foreach ( $statuses as $status ) {
			$name = self::PARAM_PREFIX . $status->slug;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging from a link.
			$value = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? (int) $_GET[ $name ] : 1;

			$pages[ $status->slug ] = min( self::MAX_PAGES, max( 1, $value ) );
		}

		return $pages;
	}

	/**
	 * URL parameters for the columns that show more than their first page.
	 *
	 * @param array<string, int> $pages Pages by status slug.
	 * @return array<string, int>
	 */
	private static function page_args( array $pages ): array {
		$args = [];
		foreach ( $pages as $slug => $shown ) {
			if ( $shown > 1 ) {
				$args[ self::PARAM_PREFIX . $slug ] = $shown;
			}
		}
		ksort( $args );

		return $args;
	}

	/**
	 * The board a roadmap is limited to, or null for a roadmap of every board. A board that
	 * no longer exists limits nothing.
	 *
	 * @param int $board_id Board term ID from the block, 0 for every board.
	 */
	private static function board( int $board_id ): ?WP_Term {
		$term = $board_id ? get_term( $board_id, BoardTaxonomy::NAME ) : null;

		return $term instanceof WP_Term ? $term : null;
	}
}
