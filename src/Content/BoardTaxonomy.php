<?php
/**
 * Board taxonomy.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Rest\ManagedTermsController;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the board taxonomy, for example one board per product. Every request is on exactly one board.
 */
final class BoardTaxonomy {

	/**
	 * Taxonomy name.
	 */
	public const NAME = 'mwtfrs_board';

	/**
	 * Registers the taxonomy.
	 */
	public static function register(): void {
		register_taxonomy(
			self::NAME,
			RequestPostType::NAME,
			[
				'labels'                => self::labels(),
				'public'                => false,
				'show_ui'               => true,
				'show_admin_column'     => true,
				'show_in_quick_edit'    => false,
				'show_in_rest'          => true,
				// Adding one over the REST API takes the capability to manage them.
				'rest_controller_class' => ManagedTermsController::class,
				'show_tagcloud'         => false,
				'hierarchical'          => false,
				'rewrite'               => false,
				// TermSelectBox adds a box with one dropdown instead.
				'meta_box_cb'           => false,
				'meta_box_sanitize_cb'  => 'taxonomy_meta_box_sanitize_cb_checkboxes',
				'capabilities'          => Capabilities::term_caps(),
			]
		);
	}

	/**
	 * Returns a board, or the default board when it is 0 or no longer exists.
	 *
	 * @param int $board_id Board term ID, or 0 for the default board.
	 */
	public static function resolve( int $board_id ): ?WP_Term {
		foreach ( [ $board_id, RequestTerms::default_term( self::NAME ) ] as $id ) {
			$term = $id ? get_term( $id, self::NAME ) : null;
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Boards created on a fresh install. The first is the default for new requests.
	 *
	 * @return array<int, array{name: string, slug: string, meta: array<string, mixed>}>
	 */
	public static function defaults(): array {
		return [
			[
				'name' => __( 'Feature Requests', 'feature-request-system' ),
				'slug' => 'feature-requests',
				'meta' => [],
			],
		];
	}

	/**
	 * Taxonomy labels.
	 *
	 * @return array<string, string|null>
	 */
	private static function labels(): array {
		return [
			'name'          => __( 'Boards', 'feature-request-system' ),
			'singular_name' => __( 'Board', 'feature-request-system' ),
			'menu_name'     => __( 'Boards', 'feature-request-system' ),
			'all_items'     => __( 'All Boards', 'feature-request-system' ),
			'edit_item'     => __( 'Edit Board', 'feature-request-system' ),
			'update_item'   => __( 'Update Board', 'feature-request-system' ),
			'add_new_item'  => __( 'Add Board', 'feature-request-system' ),
			'new_item_name' => __( 'New Board Name', 'feature-request-system' ),
			'search_items'  => __( 'Search Boards', 'feature-request-system' ),
			'not_found'     => __( 'No boards found.', 'feature-request-system' ),
			'back_to_items' => __( '&larr; Go to Boards', 'feature-request-system' ),
			'popular_items' => null,
		];
	}
}
