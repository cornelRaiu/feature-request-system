<?php
/**
 * The address of a merged request.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Merging\MergedStatus;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Sends everyone who opens a merged request's address to the request it was merged
 * into, with a permanent redirect, so links and search engines move along with the votes.
 *
 * The merged status is not public, so WordPress finds the request and then answers that
 * the page does not exist. The request it found is remembered on the way, whichever form
 * the address has, and the redirect replaces that answer.
 */
final class MergeRedirect {

	/**
	 * Priority on template_redirect: before WordPress guesses where an address that does
	 * not exist should go, which could be another request with a similar name.
	 */
	private const PRIORITY = 9;

	/**
	 * ID of the merged request that the main query found, or 0.
	 *
	 * @var int
	 */
	private static $merged = 0;

	/**
	 * Adds the hooks that find a merged request and redirect from it.
	 */
	public static function add_hooks(): void {
		add_filter( 'posts_results', [ self::class, 'remember' ], 10, 2 );
		add_action( 'template_redirect', [ self::class, 'redirect' ], self::PRIORITY );
	}

	/**
	 * Remembers the merged request that the page's main query found, before WordPress
	 * drops it because its status is not public.
	 *
	 * @param WP_Post[] $posts Posts the query found.
	 * @param WP_Query  $query The query.
	 * @return WP_Post[]
	 */
	public static function remember( array $posts, WP_Query $query ): array {
		if ( $query->is_main_query() && $query->is_singular() ) {
			$post         = $posts[0] ?? null;
			self::$merged = $post instanceof WP_Post && RequestPostType::NAME === $post->post_type && MergedStatus::NAME === $post->post_status ? $post->ID : 0;
		}

		return $posts;
	}

	/**
	 * Redirects from a merged request to the request it now lives on.
	 */
	public static function redirect(): void {
		$location = self::location();
		if ( '' === $location ) {
			return;
		}

		wp_safe_redirect( $location, 301 );
		exit;
	}

	/**
	 * Where the current page redirects to, or '' when it is not a merged request's page.
	 */
	public static function location(): string {
		$target = self::$merged && is_404() ? MergedStatus::destination( self::$merged ) : 0;

		return $target ? (string) get_permalink( $target ) : '';
	}
}
