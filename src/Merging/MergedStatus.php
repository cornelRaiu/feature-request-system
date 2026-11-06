<?php
/**
 * The post status of a merged request.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Merging;

use FeatureRequestSystem\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * A request that was merged into another keeps its page, its comments and its own post
 * status, which is not public: its address leads to the request it was merged into.
 *
 * Merging cannot be undone, so a merged request stays merged whatever saves it, except
 * that it can go to the trash and come back from it.
 */
final class MergedStatus {

	/**
	 * Post status of a merged request.
	 */
	public const NAME = 'mwtfrs_merged';

	/**
	 * Post meta: ID of the request this one was merged into.
	 */
	public const META_INTO = '_mwtfrs_merged_into';

	/**
	 * Most merges in a row that a request's address follows, in case a chain loops.
	 */
	private const MAX_HOPS = 10;

	/**
	 * Registers the status. Not public, so nobody sees a merged request's page, and with
	 * its own view in the admin's list of requests, outside "All".
	 */
	public static function register(): void {
		register_post_status(
			self::NAME,
			[
				'label'                     => _x( 'Merged', 'post status of a request merged into another', 'feature-request-system' ),
				/* translators: %s: number of merged requests. */
				'label_count'               => _n_noop( 'Merged <span class="count">(%s)</span>', 'Merged <span class="count">(%s)</span>', 'feature-request-system' ),
				'public'                    => false,
				// Not a draft that its editors may preview, nor private content that some may read: nobody sees the page.
				'protected'                 => false,
				'private'                   => false,
				'internal'                  => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
			]
		);
	}

	/**
	 * Adds the hook that keeps merged requests merged.
	 */
	public static function add_hooks(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'keep_merged' ], 10, 2 );
	}

	/**
	 * Keeps a merged request in its status when it is saved, from the edit screen, Quick
	 * Edit or code. Going to the trash is allowed, and coming back from it returns the
	 * request to this status.
	 *
	 * @param array<string, mixed> $data    Post fields about to be saved.
	 * @param array<string, mixed> $postarr Post fields as passed, with the ID of a post that exists.
	 * @return array<string, mixed>
	 */
	public static function keep_merged( array $data, array $postarr ): array {
		$post_id = (int) ( $postarr['ID'] ?? 0 );

		if ( $post_id && RequestPostType::NAME === ( $data['post_type'] ?? '' ) && 'trash' !== ( $data['post_status'] ?? '' ) && self::into( $post_id ) ) {
			$data['post_status'] = self::NAME;
		}

		return $data;
	}

	/**
	 * Whether a request is merged.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function is_merged( int $request_id ): bool {
		return self::NAME === get_post_status( $request_id );
	}

	/**
	 * ID of the request that a request was merged into, or 0.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function into( int $request_id ): int {
		return (int) get_post_meta( $request_id, self::META_INTO, true );
	}

	/**
	 * The published request that a merged request now lives on, or 0 when there is none.
	 *
	 * A request can be merged into one that is merged later itself, so this follows the
	 * merges to the last one.
	 *
	 * @param int $request_id ID of a merged request.
	 */
	public static function destination( int $request_id ): int {
		$current = $request_id;
		$hops    = 0;

		while ( $current && $hops < self::MAX_HOPS && self::is_merged( $current ) ) {
			$current = self::into( $current );
			++$hops;
		}

		return $current && $current !== $request_id && 'publish' === get_post_status( $current ) ? $current : 0;
	}
}
