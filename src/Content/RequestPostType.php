<?php
/**
 * Feature request post type.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type that stores feature requests.
 */
final class RequestPostType {

	/**
	 * Post type name.
	 */
	public const NAME = 'mwtfrs_request';

	/**
	 * Plural base for the post type's capabilities, such as edit_mwtfrs_requests.
	 */
	public const CAPABILITY_TYPE = self::NAME . 's';

	/**
	 * URL base for single requests.
	 */
	public const REWRITE_SLUG = 'feature-requests';

	/**
	 * Post meta holding the vote count. Every request has it from the moment it is created,
	 * so sorting by votes never leaves a request out.
	 */
	public const META_VOTES = '_mwtfrs_votes';

	/**
	 * Registers the post type and its meta.
	 */
	public static function register(): void {
		register_post_type(
			self::NAME,
			[
				'labels'           => self::labels(),
				'public'           => true,
				'has_archive'      => false,
				'rewrite'          => [
					'slug'       => self::REWRITE_SLUG,
					'with_front' => false,
				],
				'menu_icon'        => 'dashicons-lightbulb',
				'menu_position'    => 26,
				'supports'         => [ 'title', 'editor', 'author', 'comments', 'revisions' ],
				'show_in_rest'     => true,
				'capability_type'  => [ self::NAME, self::CAPABILITY_TYPE ],
				'map_meta_cap'     => true,
				'delete_with_user' => false,
			]
		);

		register_post_meta(
			self::NAME,
			self::META_VOTES,
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			]
		);
	}

	/**
	 * Adds the post type's hooks.
	 */
	public static function add_hooks(): void {
		add_filter( 'use_block_editor_for_post_type', [ self::class, 'use_block_editor' ], 10, 2 );
	}

	/**
	 * Keeps requests in the classic editor, where the board and status boxes allow one choice each.
	 *
	 * @param bool   $use_block_editor Whether the block editor would be used.
	 * @param string $post_type        Post type being edited.
	 */
	public static function use_block_editor( bool $use_block_editor, string $post_type ): bool {
		return self::NAME === $post_type ? false : $use_block_editor;
	}

	/**
	 * Post type labels.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'name'                  => __( 'Feature Requests', 'feature-request-system' ),
			'singular_name'         => __( 'Feature Request', 'feature-request-system' ),
			'menu_name'             => __( 'Feature Requests', 'feature-request-system' ),
			'all_items'             => __( 'All Requests', 'feature-request-system' ),
			'add_new'               => __( 'Add Request', 'feature-request-system' ),
			'add_new_item'          => __( 'Add Feature Request', 'feature-request-system' ),
			'edit_item'             => __( 'Edit Feature Request', 'feature-request-system' ),
			'new_item'              => __( 'New Feature Request', 'feature-request-system' ),
			'view_item'             => __( 'View Feature Request', 'feature-request-system' ),
			'view_items'            => __( 'View Feature Requests', 'feature-request-system' ),
			'search_items'          => __( 'Search Feature Requests', 'feature-request-system' ),
			'not_found'             => __( 'No feature requests found.', 'feature-request-system' ),
			'not_found_in_trash'    => __( 'No feature requests found in Trash.', 'feature-request-system' ),
			'item_published'        => __( 'Feature request published.', 'feature-request-system' ),
			'item_updated'          => __( 'Feature request updated.', 'feature-request-system' ),
			'filter_items_list'     => __( 'Filter feature requests list', 'feature-request-system' ),
			'items_list_navigation' => __( 'Feature requests list navigation', 'feature-request-system' ),
			'items_list'            => __( 'Feature requests list', 'feature-request-system' ),
		];
	}
}
