<?php
/**
 * Request category taxonomy.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

use FeatureRequestSystem\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the optional category taxonomy. A request can have any number of categories.
 */
final class CategoryTaxonomy {

	/**
	 * Taxonomy name.
	 */
	public const NAME = 'mwtfrs_category';

	/**
	 * Registers the taxonomy.
	 */
	public static function register(): void {
		register_taxonomy(
			self::NAME,
			RequestPostType::NAME,
			[
				'labels'            => self::labels(),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'show_tagcloud'     => false,
				'hierarchical'      => true,
				'rewrite'           => false,
				'capabilities'      => Capabilities::term_caps(),
			]
		);
	}

	/**
	 * Taxonomy labels.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'name'              => __( 'Categories', 'feature-request-system' ),
			'singular_name'     => __( 'Category', 'feature-request-system' ),
			'menu_name'         => __( 'Categories', 'feature-request-system' ),
			'all_items'         => __( 'All Categories', 'feature-request-system' ),
			'edit_item'         => __( 'Edit Category', 'feature-request-system' ),
			'update_item'       => __( 'Update Category', 'feature-request-system' ),
			'add_new_item'      => __( 'Add Category', 'feature-request-system' ),
			'new_item_name'     => __( 'New Category Name', 'feature-request-system' ),
			'parent_item'       => __( 'Parent Category', 'feature-request-system' ),
			'parent_item_colon' => __( 'Parent Category:', 'feature-request-system' ),
			'search_items'      => __( 'Search Categories', 'feature-request-system' ),
			'not_found'         => __( 'No categories found.', 'feature-request-system' ),
			'back_to_items'     => __( '&larr; Go to Categories', 'feature-request-system' ),
		];
	}
}
