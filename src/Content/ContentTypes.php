<?php
/**
 * Registration of all content types.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Content;

use FeatureRequestSystem\Merging\MergedStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type, its taxonomies and its post status in one call, for init, activation and uninstall.
 */
final class ContentTypes {

	/**
	 * Registers the request post type, its taxonomies and the status of merged requests.
	 */
	public static function register(): void {
		RequestPostType::register();
		BoardTaxonomy::register();
		StatusTaxonomy::register();
		CategoryTaxonomy::register();
		MergedStatus::register();
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
