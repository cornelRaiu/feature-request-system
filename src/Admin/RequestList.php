<?php
/**
 * The requests list in the admin.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Voting\VoteCount;
use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a sortable Votes column to the requests list, puts the columns in a useful order,
 * shows each status with its colour, and adds filters for status, board and category.
 */
final class RequestList {

	/**
	 * Key of the Votes column, also the value of the orderby parameter when sorting by votes.
	 */
	public const COLUMN_VOTES = 'mwtfrs_vote_count';

	/**
	 * Adds the hooks for the list.
	 */
	public static function add_hooks(): void {
		add_filter( 'manage_' . RequestPostType::NAME . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . RequestPostType::NAME . '_posts_custom_column', [ self::class, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-' . RequestPostType::NAME . '_sortable_columns', [ self::class, 'sortable_columns' ] );
		add_action( 'pre_get_posts', [ self::class, 'sort_by_votes' ] );
		add_action( 'restrict_manage_posts', [ self::class, 'render_filters' ] );
		add_filter( 'post_column_taxonomy_links', [ self::class, 'color_status' ], 10, 3 );
	}

	/**
	 * Key of the column WordPress adds for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function taxonomy_column( string $taxonomy ): string {
		return 'taxonomy-' . $taxonomy;
	}

	/**
	 * Puts Votes, Status, Board and Categories after the title, in that order.
	 *
	 * @param array<string, string> $columns Columns of the list.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$after_title = [
			self::COLUMN_VOTES                            => __( 'Votes', 'feature-request-system' ),
			self::taxonomy_column( StatusTaxonomy::NAME ) => __( 'Status', 'feature-request-system' ),
			self::taxonomy_column( BoardTaxonomy::NAME )  => __( 'Board', 'feature-request-system' ),
			self::taxonomy_column( CategoryTaxonomy::NAME ) => __( 'Categories', 'feature-request-system' ),
		];

		$ordered = [];
		foreach ( array_diff_key( $columns, $after_title ) as $key => $label ) {
			$ordered[ $key ] = $label;
			if ( 'title' === $key ) {
				$ordered += $after_title;
			}
		}

		return $ordered;
	}

	/**
	 * Prints the vote count.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Request ID.
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN_VOTES === $column ) {
			echo esc_html( number_format_i18n( VoteCount::get( $post_id ) ) );
		}
	}

	/**
	 * Makes the Votes column sortable, most votes first.
	 *
	 * @param array<string, mixed> $columns Sortable columns.
	 * @return array<string, mixed>
	 */
	public static function sortable_columns( array $columns ): array {
		$columns[ self::COLUMN_VOTES ] = [ self::COLUMN_VOTES, true ];

		return $columns;
	}

	/**
	 * Sorts the list by the stored vote count when the Votes column is chosen.
	 *
	 * @param WP_Query $query Query about to run.
	 */
	public static function sort_by_votes( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || RequestPostType::NAME !== $query->get( 'post_type' ) || self::COLUMN_VOTES !== $query->get( 'orderby' ) ) {
			return;
		}

		// Every request has this meta from creation, so none drop out of the list.
		$query->set( 'meta_key', RequestPostType::META_VOTES );
		$query->set(
			'orderby',
			[
				'meta_value_num' => 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC',
				'date'           => 'DESC',
			]
		);
	}

	/**
	 * Prints the status, board and category filters above the list.
	 *
	 * @param string $post_type Post type of the list.
	 */
	public static function render_filters( string $post_type ): void {
		if ( RequestPostType::NAME !== $post_type ) {
			return;
		}

		// A filter is shown from this many terms: one board or no category leaves nothing to choose.
		$filters = [
			StatusTaxonomy::NAME   => [ __( 'All statuses', 'feature-request-system' ), __( 'Filter by status', 'feature-request-system' ), 1 ],
			BoardTaxonomy::NAME    => [ __( 'All boards', 'feature-request-system' ), __( 'Filter by board', 'feature-request-system' ), 2 ],
			CategoryTaxonomy::NAME => [ __( 'All categories', 'feature-request-system' ), __( 'Filter by category', 'feature-request-system' ), 1 ],
		];

		foreach ( $filters as $taxonomy => list( $all, $label, $minimum ) ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				]
			);
			if ( ! is_array( $terms ) || count( $terms ) < $minimum ) {
				continue;
			}

			if ( StatusTaxonomy::NAME === $taxonomy ) {
				$terms = StatusTaxonomy::sort( $terms );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter of a list.
			$selected = isset( $_GET[ $taxonomy ] ) ? sanitize_title( wp_unslash( $_GET[ $taxonomy ] ) ) : '';

			printf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%3$s" id="%1$s"><option value="">%4$s</option>',
				esc_attr( 'filter-by-' . $taxonomy ),
				esc_html( $label ),
				esc_attr( $taxonomy ),
				esc_html( $all )
			);
			foreach ( $terms as $term ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $term->slug ),
					selected( $selected, $term->slug, false ),
					esc_html( $term->name )
				);
			}
			echo '</select>';
		}
	}

	/**
	 * Puts each status's colour in front of its name in the Status column.
	 *
	 * @param string[]  $links    Links to the terms of one request.
	 * @param string    $taxonomy Taxonomy of the column.
	 * @param WP_Term[] $terms    The terms, in the order of the links.
	 * @return string[]
	 */
	public static function color_status( array $links, string $taxonomy, array $terms ): array {
		if ( StatusTaxonomy::NAME !== $taxonomy ) {
			return $links;
		}

		foreach ( array_values( $terms ) as $index => $term ) {
			if ( isset( $links[ $index ] ) ) {
				$links[ $index ] = sprintf(
					'<span aria-hidden="true" style="display:inline-block;width:0.625em;height:0.625em;margin-right:0.375em;border-radius:50%%;background:%s"></span>',
					esc_attr( StatusTaxonomy::color( $term ) )
				) . $links[ $index ];
			}
		}

		return $links;
	}
}
