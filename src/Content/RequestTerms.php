<?php
/**
 * Rules that keep every request's board, status and vote count valid.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

use FeatureRequestSystem\Settings;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each request on exactly one board and in exactly one status, and keeps its vote count set.
 *
 * The rules run on data changes rather than in one screen, so they hold for the editor,
 * the REST API, WP-CLI and other plugins alike.
 */
final class RequestTerms {

	/**
	 * Taxonomies where a request has exactly one term, mapped to the setting that stores the default term.
	 */
	private const SINGLE_TERM_TAXONOMIES = [
		BoardTaxonomy::NAME  => Settings::DEFAULT_BOARD,
		StatusTaxonomy::NAME => Settings::DEFAULT_STATUS,
	];

	/**
	 * Adds the hooks that enforce the rules.
	 */
	public static function add_hooks(): void {
		add_action( 'wp_after_insert_post', [ self::class, 'ensure_defaults' ], 10, 2 );
		add_action( 'set_object_terms', [ self::class, 'keep_single_term' ], 10, 6 );
		add_action( 'delete_term', [ self::class, 'reassign_orphans' ], 10, 5 );
		add_filter( 'map_meta_cap', [ self::class, 'protect_default_terms' ], 10, 4 );
	}

	/**
	 * Returns the default term ID for the board or status taxonomy, or 0 for any other taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function default_term( string $taxonomy ): int {
		if ( ! isset( self::SINGLE_TERM_TAXONOMIES[ $taxonomy ] ) ) {
			return 0;
		}

		return (int) Settings::get( self::SINGLE_TERM_TAXONOMIES[ $taxonomy ] );
	}

	/**
	 * Gives a saved request a vote count and the default board and status when it has none.
	 *
	 * Runs on wp_after_insert_post, after the editor or the REST API has set the request's terms.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Saved post.
	 */
	public static function ensure_defaults( int $post_id, WP_Post $post ): void {
		if ( RequestPostType::NAME !== $post->post_type ) {
			return;
		}

		add_post_meta( $post_id, RequestPostType::META_VOTES, 0, true );

		foreach ( array_keys( self::SINGLE_TERM_TAXONOMIES ) as $taxonomy ) {
			self::assign_default_if_empty( $post_id, $taxonomy );
		}
	}

	/**
	 * Leaves a request with one board or status after terms are set, keeping the one just added.
	 *
	 * When several terms are added at once, the last one wins.
	 *
	 * @param int               $object_id  Object the terms were set on.
	 * @param mixed             $terms      Terms passed to wp_set_object_terms().
	 * @param array<int|string> $tt_ids     Term taxonomy IDs passed in this call.
	 * @param string            $taxonomy   Taxonomy name.
	 * @param bool              $append     Whether the terms were appended.
	 * @param array<int|string> $old_tt_ids Term taxonomy IDs before the call.
	 */
	public static function keep_single_term( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( ! isset( self::SINGLE_TERM_TAXONOMIES[ $taxonomy ] ) || ! $tt_ids ) {
			return;
		}

		$current = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'tt_ids' ] );
		if ( ! is_array( $current ) || count( $current ) < 2 ) {
			return;
		}

		$passed = array_map( 'intval', $tt_ids );
		$added  = array_diff( $passed, array_map( 'intval', $old_tt_ids ) );
		$keep   = $added ? end( $added ) : end( $passed );
		$term   = get_term_by( 'term_taxonomy_id', $keep, $taxonomy );

		if ( $term instanceof WP_Term ) {
			wp_set_object_terms( $object_id, $term->term_id, $taxonomy );
		}
	}

	/**
	 * Records a term as the default for the board or status taxonomy.
	 *
	 * @param string $taxonomy Board or status taxonomy.
	 * @param int    $term_id  Term ID, or 0 when the taxonomy has no terms.
	 */
	public static function set_default_term( string $taxonomy, int $term_id ): void {
		if ( isset( self::SINGLE_TERM_TAXONOMIES[ $taxonomy ] ) ) {
			Settings::update( self::SINGLE_TERM_TAXONOMIES[ $taxonomy ], $term_id );
		}
	}

	/**
	 * Makes the oldest term in the taxonomy its default.
	 *
	 * @param string $taxonomy Board or status taxonomy.
	 * @return int The new default term ID, or 0 when the taxonomy has no terms.
	 */
	public static function promote_oldest_term( string $taxonomy ): int {
		$oldest  = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'term_id',
				'number'     => 1,
			]
		);
		$term_id = is_array( $oldest ) && $oldest ? (int) $oldest[0] : 0;

		self::set_default_term( $taxonomy, $term_id );

		return $term_id;
	}

	/**
	 * Moves requests to the default board or status when theirs is deleted.
	 *
	 * The admin screens cannot delete the default itself, but code such as WP-CLI can.
	 * In that case the oldest remaining term becomes the default first.
	 *
	 * @param int        $term_id      Deleted term ID.
	 * @param int        $tt_id        Deleted term taxonomy ID.
	 * @param string     $taxonomy     Taxonomy name.
	 * @param mixed      $deleted_term Copy of the deleted term.
	 * @param array<int> $object_ids   Objects that had the term.
	 */
	public static function reassign_orphans( int $term_id, int $tt_id, string $taxonomy, $deleted_term, array $object_ids ): void {
		if ( ! isset( self::SINGLE_TERM_TAXONOMIES[ $taxonomy ] ) ) {
			return;
		}

		if ( self::default_term( $taxonomy ) === $term_id ) {
			self::promote_oldest_term( $taxonomy );
		}

		foreach ( $object_ids as $object_id ) {
			self::assign_default_if_empty( (int) $object_id, $taxonomy );
		}
	}

	/**
	 * Stops anyone from deleting the default board or status, the same way WordPress protects the default category.
	 *
	 * @param string[]     $caps    Primitive capabilities the user needs.
	 * @param string       $cap     Capability being checked.
	 * @param int          $user_id User ID.
	 * @param array<mixed> $args    Extra arguments. For delete_term, the term ID comes first.
	 * @return string[]
	 */
	public static function protect_default_terms( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_term' !== $cap || empty( $args[0] ) ) {
			return $caps;
		}

		$term = get_term( (int) $args[0] );
		if ( $term instanceof WP_Term && self::default_term( $term->taxonomy ) === $term->term_id ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * Assigns the taxonomy's default term to a request that has no term in it.
	 *
	 * @param int    $post_id  Request ID.
	 * @param string $taxonomy Board or status taxonomy.
	 */
	private static function assign_default_if_empty( int $post_id, string $taxonomy ): void {
		$assigned = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( ! is_array( $assigned ) || $assigned ) {
			return;
		}

		$default = self::default_term( $taxonomy );
		if ( $default && term_exists( $default, $taxonomy ) ) {
			wp_set_object_terms( $post_id, $default, $taxonomy );
		}
	}
}
