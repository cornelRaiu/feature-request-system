<?php
/**
 * Data removal on uninstall.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Install;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\ContentTypes;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes everything the plugin stored, but only on sites that opted in with the
 * "delete data on uninstall" setting. Without it, uninstalling leaves all data in place.
 */
final class Uninstaller {

	/**
	 * Removes the current site's plugin data if the site opted in.
	 */
	public static function run(): void {
		if ( ! Settings::get( Settings::DELETE_DATA_ON_UNINSTALL ) ) {
			return;
		}

		// Posts and terms can only be deleted cleanly while their types are registered.
		ContentTypes::register();

		self::delete_requests();
		self::delete_terms();
		Schema::drop_tables();
		Capabilities::revoke();

		delete_option( Settings::OPTION );
		delete_option( Installer::OPTION_DB_VERSION );
		delete_option( Installer::LOCK_OPTION );
	}

	/**
	 * Deletes every request in any status, with its comments, meta, revisions and term links.
	 */
	private static function delete_requests(): void {
		global $wpdb;

		do {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Covers every post status, including trash and auto-draft.
				$wpdb->prepare( 'SELECT ID FROM %i WHERE post_type = %s LIMIT 100', $wpdb->posts, RequestPostType::NAME )
			);

			$deleted = 0;
			foreach ( $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					++$deleted;
				}
			}
		} while ( $deleted > 0 );
	}

	/**
	 * Deletes every board, status and category, with their term meta.
	 */
	private static function delete_terms(): void {
		foreach ( ContentTypes::taxonomies() as $taxonomy ) {
			$term_ids = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				]
			);
			if ( ! is_array( $term_ids ) ) {
				continue;
			}

			foreach ( $term_ids as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
	}
}
