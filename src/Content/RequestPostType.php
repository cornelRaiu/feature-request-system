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
	 * Post meta: ID of the logged-in user who submitted the request from the front end.
	 */
	public const META_SUBMITTER_ID = '_mwtfrs_submitter_id';

	/**
	 * Post meta: name a visitor gave when submitting without an account. Never shown publicly.
	 */
	public const META_SUBMITTER_NAME = '_mwtfrs_submitter_name';

	/**
	 * Post meta: email a visitor gave when submitting without an account. Never shown publicly.
	 */
	public const META_SUBMITTER_EMAIL = '_mwtfrs_submitter_email';

	/**
	 * Post meta: set when a spam filter such as Akismet flagged the submission, which is then held for review.
	 */
	public const META_FLAGGED_AS_SPAM = '_mwtfrs_flagged_as_spam';

	/**
	 * The menu's icon: the plugin's logo in one colour. The cards behind are lighter, and the
	 * vote arrow and the title are cut out of the card in front.
	 *
	 * WordPress paints an SVG menu icon in the colours of the admin colour scheme by rewriting
	 * every fill in it. So each shape has a fill, and nothing is drawn with a stroke.
	 */
	private const MENU_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
		. '<path fill="#a7aaad" fill-opacity=".45" d="M4 4V2.5A1.5 1.5 0 0 1 5.5 1h9A1.5 1.5 0 0 1 16 2.5V4z"/>'
		. '<path fill="#a7aaad" fill-opacity=".7" d="M2 8V5.5A1.5 1.5 0 0 1 3.5 4h13A1.5 1.5 0 0 1 18 5.5V8z"/>'
		. '<path fill="#a7aaad" fill-rule="evenodd" d="M3 8h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2zm2.5 3.2L8 13.7l-1.131 1.131L5.5 13.463l-1.369 1.368L3 13.7zM11 12h5a1 1 0 0 1 0 2h-5a1 1 0 0 1 0-2z"/>'
		. '</svg>';

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
				'menu_icon'        => 'data:image/svg+xml;base64,' . base64_encode( self::MENU_ICON ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress paints an SVG menu icon only when it comes base64-encoded.
				'menu_position'    => 26,
				// No author support: front-end submitters are not WordPress authors, so their names
				// stay out of author archives, oEmbed data and the public users endpoint.
				'supports'         => [ 'title', 'editor', 'comments', 'revisions' ],
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
