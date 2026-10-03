<?php
/**
 * The board in its compact form: a short list of feature requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use WP_Post;
use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the board block in its compact form: the most-voted or the newest requests,
 * with their vote buttons and a link to the whole board. For a home page, a sidebar or
 * the end of a post.
 *
 * The list has no search, filters or pages, and it ignores the board's filters in the URL,
 * so it shows the same requests on every page it sits on. Like the board, its HTML is the
 * same for every visitor.
 */
final class CompactBoard {

	/**
	 * Requests shown when the block does not say how many.
	 */
	private const DEFAULT_COUNT = 5;

	/**
	 * Most requests a list may show.
	 */
	private const MAX_COUNT = 20;

	/**
	 * Renders a list. Called by the board block's render.php when the block is compact.
	 *
	 * @param array<string, mixed> $attributes         Block attributes: board (term ID, 0 for every board), perPage, sort and status.
	 * @param string               $wrapper_attributes Attributes from get_block_wrapper_attributes().
	 */
	public static function render( array $attributes, string $wrapper_attributes ): string {
		$board    = self::board( (int) ( $attributes['board'] ?? 0 ) );
		$board_id = $board ? $board->term_id : 0;
		$count    = min( self::MAX_COUNT, max( 1, (int) ( $attributes['perPage'] ?? self::DEFAULT_COUNT ) ) );
		$filters  = [
			'search'   => '',
			'status'   => sanitize_title( (string) ( $attributes['status'] ?? '' ) ),
			'category' => '',
			'sort'     => BoardQuery::SORT_NEW === ( $attributes['sort'] ?? '' ) ? BoardQuery::SORT_NEW : BoardQuery::SORT_TOP,
			'page'     => 1,
		];
		// Nothing here needs the number of requests there are in all.
		$query    = new WP_Query( [ 'no_found_rows' => true ] + BoardQuery::args( $board_id, $filters, $count ) );
		$notice   = RequestNotice::from_request();
		$page_url = CurrentPage::url();
		$instance = wp_unique_id( 'mwtfrs-list-' );

		VoteStore::provide();

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				// A list of every board follows each request's own board.
				$of      = $board ? $board : RequestTerms::term_of( $post->ID, BoardTaxonomy::NAME );
				$items[] = RequestItem::prepare( $post, BoardSettings::voting( $of ? $of->term_id : 0 ), $notice, $instance );
			}
		}

		return Template::render(
			'request-list',
			[
				'namespace'          => VoteStore::NAME,
				'wrapper_attributes' => $wrapper_attributes,
				'context'            => VoteStore::context( $items ),
				'items'              => $items,
				// Not headings: a list can sit anywhere, under a heading of any level.
				'title_tag'          => 'p',
				'compact'            => true,
				'more_url'           => self::board_url( $board_id, $page_url ),
				'vote'               => VoteForm::template_args( $page_url ),
				'login_url'          => wp_login_url( $page_url ),
			]
		);
	}

	/**
	 * The board a list is limited to, or null for a list of every board. A board that no
	 * longer exists limits nothing.
	 *
	 * @param int $board_id Board term ID from the block, 0 for every board.
	 */
	private static function board( int $board_id ): ?WP_Term {
		$term = $board_id ? get_term( $board_id, BoardTaxonomy::NAME ) : null;

		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Where "See all requests" leads: the page that shows the board, or '' when there is
	 * none to send people to.
	 *
	 * @param int    $board_id Board term ID, 0 for every board.
	 * @param string $page_url Page the list is on.
	 */
	private static function board_url( int $board_id, string $page_url ): string {
		if ( ! $board_id ) {
			// A list of every board has one page to lead to only on a site with one board.
			$boards = get_terms(
				[
					'taxonomy'   => BoardTaxonomy::NAME,
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => 2,
				]
			);
			if ( ! is_array( $boards ) || 1 !== count( $boards ) ) {
				return '';
			}
			$board_id = (int) $boards[0];
		}

		$url = BoardSettings::page_url( $board_id );

		// On the board's own page, the link would lead to where the visitor already is.
		return untrailingslashit( $url ) === untrailingslashit( $page_url ) ? '' : $url;
	}
}
