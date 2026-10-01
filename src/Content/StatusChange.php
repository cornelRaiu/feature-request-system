<?php
/**
 * Status changes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Announces when a request moves from one status to another.
 *
 * Status is a taxonomy term, so it can change from the edit screen, Quick Edit, Bulk Edit,
 * the REST API, WP-CLI or other code. All of them end in wp_set_object_terms(), which is
 * where this listens, so the action fires once per change whichever way it was made.
 */
final class StatusChange {

	/**
	 * Action fired when a request's status changes. Receives the request ID, the old
	 * status term and the new status term.
	 */
	public const ACTION = 'mwtfrs_status_changed';

	/**
	 * Status term IDs that requests had just before a status was added to them, by request ID.
	 *
	 * @var array<int, int[]>
	 */
	private static $before = [];

	/**
	 * Listens for a status being added to a request, and for the end of that change.
	 */
	public static function add_hooks(): void {
		add_action( 'add_term_relationship', [ self::class, 'remember' ], 10, 3 );
		// After RequestTerms has left the request with one status, and other plugins had their say.
		add_action( 'set_object_terms', [ self::class, 'announce' ], 20, 4 );
	}

	/**
	 * Notes the status a request has, right before WordPress adds another one to it.
	 *
	 * WordPress only reports the previous terms when it replaces them, not when it adds
	 * to them, so they are read here, while they are still unchanged.
	 *
	 * @param int    $object_id Object getting the term.
	 * @param int    $tt_id     Term taxonomy ID being added.
	 * @param string $taxonomy  Taxonomy name.
	 */
	public static function remember( int $object_id, int $tt_id, string $taxonomy ): void {
		if ( StatusTaxonomy::NAME !== $taxonomy || isset( self::$before[ $object_id ] ) ) {
			return;
		}

		$statuses                   = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'ids' ] );
		self::$before[ $object_id ] = is_array( $statuses ) ? array_map( 'intval', $statuses ) : [];
	}

	/**
	 * Fires the action when a request ended up in a different status than it had.
	 *
	 * A request that had no status yet, such as a new one getting its first, is not a change.
	 * Nor is a change that other code undid before this runs.
	 *
	 * @param int    $object_id Object the terms were set on.
	 * @param mixed  $terms     Terms passed to wp_set_object_terms().
	 * @param mixed  $tt_ids    Term taxonomy IDs passed in this call.
	 * @param string $taxonomy  Taxonomy name.
	 */
	public static function announce( int $object_id, $terms, $tt_ids, string $taxonomy ): void {
		if ( StatusTaxonomy::NAME !== $taxonomy || ! isset( self::$before[ $object_id ] ) ) {
			return;
		}

		$before = self::$before[ $object_id ];
		unset( self::$before[ $object_id ] );

		$old = 1 === count( $before ) ? get_term( $before[0], $taxonomy ) : null;
		$new = RequestTerms::term_of( $object_id, $taxonomy );

		if ( $old instanceof WP_Term && $new && $old->term_id !== $new->term_id && RequestPostType::NAME === get_post_type( $object_id ) ) {
			do_action( self::ACTION, $object_id, $old, $new ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.
		}
	}
}
