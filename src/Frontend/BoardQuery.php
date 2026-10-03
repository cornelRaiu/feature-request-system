<?php
/**
 * Board list queries.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the board's URL filters into WP_Query arguments.
 *
 * Filters travel in the query string, so a filtered board is an ordinary GET page that
 * works without JavaScript and can be bookmarked or cached.
 */
final class BoardQuery {

	/**
	 * URL parameter: search text.
	 */
	public const PARAM_SEARCH = 'fr-search';

	/**
	 * URL parameter: status slug, STATUS_ALL, or empty for requests still open for voting.
	 */
	public const PARAM_STATUS = 'fr-status';

	/**
	 * Status filter value that shows requests in every status, closed ones included.
	 */
	public const STATUS_ALL = 'all';

	/**
	 * URL parameter: category slug.
	 */
	public const PARAM_CATEGORY = 'fr-category';

	/**
	 * URL parameter: sort order, one of the SORT_* values.
	 */
	public const PARAM_SORT = 'fr-sort';

	/**
	 * URL parameter: page number.
	 */
	public const PARAM_PAGE = 'fr-page';

	/**
	 * Most votes first. The default.
	 */
	public const SORT_TOP = 'top';

	/**
	 * Newest first.
	 */
	public const SORT_NEW = 'new';

	/**
	 * Reads the filters from the current URL.
	 *
	 * @return array{search: string, status: string, category: string, sort: string, page: int}
	 */
	public static function filters_from_request(): array {
		$sort = self::param( self::PARAM_SORT );

		return [
			'search'   => self::param( self::PARAM_SEARCH ),
			'status'   => sanitize_title( self::param( self::PARAM_STATUS ) ),
			'category' => sanitize_title( self::param( self::PARAM_CATEGORY ) ),
			'sort'     => self::SORT_NEW === $sort ? self::SORT_NEW : self::SORT_TOP,
			'page'     => max( 1, absint( self::param( self::PARAM_PAGE ) ) ),
		];
	}

	/**
	 * Builds the query for one page of a board.
	 *
	 * @param int                                                                              $board_id Board term ID, or 0 for the requests of every board.
	 * @param array{search: string, status: string, category: string, sort: string, page: int} $filters  Filters, as from filters_from_request().
	 * @param int                                                                              $per_page Requests per page.
	 * @return array<string, mixed> WP_Query arguments.
	 */
	public static function args( int $board_id, array $filters, int $per_page ): array {
		$tax_query = [];
		if ( $board_id ) {
			$tax_query[] = [
				'taxonomy' => BoardTaxonomy::NAME,
				'field'    => 'term_id',
				'terms'    => $board_id,
			];
		}
		$closed = StatusTaxonomy::closed_ids();
		if ( '' === $filters['status'] && $closed ) {
			// By default the board shows what people can still vote on.
			$tax_query[] = [
				'taxonomy' => StatusTaxonomy::NAME,
				'field'    => 'term_id',
				'terms'    => $closed,
				'operator' => 'NOT IN',
			];
		} elseif ( '' !== $filters['status'] && self::STATUS_ALL !== $filters['status'] ) {
			$tax_query[] = [
				'taxonomy' => StatusTaxonomy::NAME,
				'field'    => 'slug',
				'terms'    => $filters['status'],
			];
		}
		if ( '' !== $filters['category'] ) {
			$tax_query[] = [
				'taxonomy' => CategoryTaxonomy::NAME,
				'field'    => 'slug',
				'terms'    => $filters['category'],
			];
		}

		$args = [
			'post_type'           => RequestPostType::NAME,
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => $per_page,
			'paged'               => $filters['page'],
			'ignore_sticky_posts' => true,
			'tax_query'           => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Boards, statuses and categories are taxonomies.
		];

		if ( '' !== $filters['search'] ) {
			$args['s'] = $filters['search'];
		}

		if ( self::SORT_TOP === $filters['sort'] ) {
			// Every request has this meta from creation, so none drop out of the list.
			$args['meta_key'] = RequestPostType::META_VOTES; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by the stored vote count.
			$args['orderby']  = [
				'meta_value_num' => 'DESC',
				'date'           => 'DESC',
			];
		} else {
			$args['orderby'] = [
				'date' => 'DESC',
				'ID'   => 'DESC',
			];
		}

		return $args;
	}

	/**
	 * Returns a GET parameter as sanitized text, or ''.
	 *
	 * @param string $name Parameter name.
	 */
	private static function param( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters from a GET form.
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
	}
}
