<?php
/**
 * Registration of all content types.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type and taxonomies in one call, for init, activation and uninstall.
 */
final class ContentTypes {

	/**
	 * Registers the request post type and its taxonomies.
	 */
	public static function register(): void {
		RequestPostType::register();
		BoardTaxonomy::register();
		StatusTaxonomy::register();
		CategoryTaxonomy::register();
	}

	/**
	 * Names of the plugin's taxonomies.
	 *
	 * @return string[]
	 */
	public static function taxonomies(): array {
		return [ BoardTaxonomy::NAME, StatusTaxonomy::NAME, CategoryTaxonomy::NAME ];
	}
}
