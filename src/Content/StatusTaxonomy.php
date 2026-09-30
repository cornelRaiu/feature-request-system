<?php
/**
 * Request status taxonomy.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

use FeatureRequestSystem\Admin\TermSelectBox;
use FeatureRequestSystem\Capabilities;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the status taxonomy. Every request has exactly one status (see RequestTerms).
 */
final class StatusTaxonomy {

	/**
	 * Taxonomy name.
	 */
	public const NAME = 'mwtfrs_status';

	/**
	 * Term meta: badge colour as a hex value.
	 */
	public const META_COLOR = '_mwtfrs_color';

	/**
	 * Term meta: position in lists and on the roadmap. Lower comes first.
	 */
	public const META_ORDER = '_mwtfrs_order';

	/**
	 * Term meta: requests in a closed status stop accepting votes.
	 */
	public const META_CLOSED = '_mwtfrs_closed';

	/**
	 * Term meta: requests in this status appear on the roadmap.
	 */
	public const META_ROADMAP = '_mwtfrs_roadmap';

	/**
	 * Registers the taxonomy and its term meta.
	 */
	public static function register(): void {
		register_taxonomy(
			self::NAME,
			RequestPostType::NAME,
			[
				'labels'               => self::labels(),
				'public'               => false,
				'show_ui'              => true,
				'show_admin_column'    => true,
				'show_in_quick_edit'   => false,
				'show_in_rest'         => true,
				'show_tagcloud'        => false,
				'hierarchical'         => false,
				'rewrite'              => false,
				'meta_box_cb'          => [ TermSelectBox::class, 'render' ],
				'meta_box_sanitize_cb' => 'taxonomy_meta_box_sanitize_cb_checkboxes',
				'capabilities'         => Capabilities::term_caps(),
			]
		);

		$meta = [
			self::META_COLOR   => [ 'string', 'sanitize_hex_color' ],
			self::META_ORDER   => [ 'integer', 'absint' ],
			self::META_CLOSED  => [ 'boolean', 'rest_sanitize_boolean' ],
			self::META_ROADMAP => [ 'boolean', 'rest_sanitize_boolean' ],
		];

		foreach ( $meta as $key => list( $type, $sanitize ) ) {
			register_term_meta(
				self::NAME,
				$key,
				[
					'type'              => $type,
					'single'            => true,
					'sanitize_callback' => $sanitize,
				]
			);
		}
	}

	/**
	 * Statuses created on a fresh install, in workflow order. The first is the default for new requests.
	 *
	 * @return array<int, array{name: string, slug: string, meta: array<string, mixed>}>
	 */
	public static function defaults(): array {
		$statuses = [
			[ __( 'Open', 'feature-request-system' ), 'open', '#64748b', false, false ],
			[ __( 'Under Review', 'feature-request-system' ), 'under-review', '#b7791f', false, false ],
			[ __( 'Planned', 'feature-request-system' ), 'planned', '#6d4fc2', false, true ],
			[ __( 'In Progress', 'feature-request-system' ), 'in-progress', '#2f5bd3', false, true ],
			[ __( 'Shipped', 'feature-request-system' ), 'shipped', '#1f8a55', true, true ],
			[ __( 'Declined', 'feature-request-system' ), 'declined', '#b4413c', true, false ],
		];

		$defaults = [];
		foreach ( $statuses as $index => list( $name, $slug, $color, $closed, $roadmap ) ) {
			$defaults[] = [
				'name' => $name,
				'slug' => $slug,
				'meta' => [
					self::META_COLOR   => $color,
					self::META_ORDER   => ( $index + 1 ) * 10,
					self::META_CLOSED  => $closed,
					self::META_ROADMAP => $roadmap,
				],
			];
		}

		return $defaults;
	}

	/**
	 * Whether requests in this status stop accepting votes.
	 *
	 * @param WP_Term $status Status term.
	 */
	public static function is_closed( WP_Term $status ): bool {
		return (bool) get_term_meta( $status->term_id, self::META_CLOSED, true );
	}

	/**
	 * Sorts statuses by their order, then by name.
	 *
	 * @param WP_Term[] $terms Status terms.
	 * @return WP_Term[]
	 */
	public static function sort( array $terms ): array {
		usort(
			$terms,
			static function ( WP_Term $a, WP_Term $b ): int {
				return [ self::order( $a ), $a->name ] <=> [ self::order( $b ), $b->name ];
			}
		);

		return $terms;
	}

	/**
	 * Returns a status's position.
	 *
	 * @param WP_Term $term Status term.
	 */
	private static function order( WP_Term $term ): int {
		return (int) get_term_meta( $term->term_id, self::META_ORDER, true );
	}

	/**
	 * Taxonomy labels.
	 *
	 * @return array<string, string|null>
	 */
	private static function labels(): array {
		return [
			'name'          => __( 'Statuses', 'feature-request-system' ),
			'singular_name' => __( 'Status', 'feature-request-system' ),
			'menu_name'     => __( 'Statuses', 'feature-request-system' ),
			'all_items'     => __( 'All Statuses', 'feature-request-system' ),
			'edit_item'     => __( 'Edit Status', 'feature-request-system' ),
			'update_item'   => __( 'Update Status', 'feature-request-system' ),
			'add_new_item'  => __( 'Add Status', 'feature-request-system' ),
			'new_item_name' => __( 'New Status Name', 'feature-request-system' ),
			'search_items'  => __( 'Search Statuses', 'feature-request-system' ),
			'not_found'     => __( 'No statuses found.', 'feature-request-system' ),
			'back_to_items' => __( '&larr; Go to Statuses', 'feature-request-system' ),
			'popular_items' => null,
		];
	}
}
