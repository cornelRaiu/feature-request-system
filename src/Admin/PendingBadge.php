<?php
/**
 * Count of requests waiting for approval, in the admin menu.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Shows moderators how many requests wait for approval, next to Feature Requests in the
 * menu, the way WordPress shows pending comments.
 */
final class PendingBadge {

	/**
	 * Adds the hook for the menu.
	 */
	public static function add_hooks(): void {
		add_filter( 'add_menu_classes', [ self::class, 'add_to_menu' ] );
	}

	/**
	 * Appends the count to the Feature Requests menu item.
	 *
	 * @param array<int|string, array<int, string>> $menu Admin menu.
	 * @return array<int|string, array<int, string>>
	 */
	public static function add_to_menu( array $menu ): array {
		$pending = current_user_can( Capabilities::MODERATE ) ? (int) wp_count_posts( RequestPostType::NAME )->pending : 0;
		if ( ! $pending ) {
			return $menu;
		}

		foreach ( $menu as $position => $item ) {
			if ( 'edit.php?post_type=' . RequestPostType::NAME === ( $item[2] ?? '' ) ) {
				$menu[ $position ][0] .= sprintf(
					' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
					$pending,
					esc_html( number_format_i18n( $pending ) ),
					/* translators: %s: number of requests. */
					esc_html( sprintf( _n( '%s request waiting for approval', '%s requests waiting for approval', $pending, 'feature-request-system' ), number_format_i18n( $pending ) ) )
				);
			}
		}

		return $menu;
	}
}
