<?php
/**
 * The page a block is shown on.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use WP;

defined( 'ABSPATH' ) || exit;

/**
 * Works out the URL of the page being rendered, to send visitors back to it after a form post.
 */
final class CurrentPage {

	/**
	 * URL of the current page, without query parameters added by the plugin's own forms.
	 */
	public static function url(): string {
		if ( is_singular() ) {
			return (string) get_permalink( get_queried_object_id() );
		}

		global $wp;

		return home_url( $wp instanceof WP ? $wp->request : '' );
	}
}
