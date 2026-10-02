<?php
/**
 * Installation and upgrades.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Install;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\ContentTypes;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Sets up tables, default terms and capabilities, on activation and whenever the stored
 * database version is behind the code.
 *
 * Running on version mismatch, and not only on activation, also covers network activation,
 * sites where the files were copied in place, and plugin updates.
 */
final class Installer {

	/**
	 * Database version of this code. Increase it whenever the tables or seeded data change.
	 */
	public const DB_VERSION = 2;

	/**
	 * Option that stores the database version a site is on.
	 */
	public const OPTION_DB_VERSION = 'mwtfrs_db_version';

	/**
	 * Option row that marks an install in progress, holding the time it started.
	 */
	public const LOCK_OPTION = 'mwtfrs_install_lock';

	/**
	 * Seconds after which a lock counts as abandoned by a request that died mid-install.
	 */
	private const LOCK_TIMEOUT = 300;

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		// Activation runs after init, so the taxonomies must be registered before terms can be seeded.
		ContentTypes::register();
		self::install();
	}

	/**
	 * Deactivation hook. Keeps all data and capabilities; only the rewrite rules are rebuilt.
	 */
	public static function deactivate(): void {
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Installs when the site's database version is behind this code. Runs on init.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or updates everything the plugin needs. Safe to run any number of times.
	 */
	public static function install(): void {
		if ( ! self::acquire_lock() ) {
			return;
		}

		Schema::create_tables();
		self::seed( BoardTaxonomy::NAME, BoardTaxonomy::defaults() );
		self::seed( StatusTaxonomy::NAME, StatusTaxonomy::defaults() );
		Capabilities::grant();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );

		// WordPress rebuilds the rules on the next request, now including the request post type.
		delete_option( 'rewrite_rules' );

		self::release_lock();
	}

	/**
	 * Creates a taxonomy's starting terms on a fresh install and records the default term.
	 *
	 * Once a valid default is recorded this does nothing, so terms the site owner renamed,
	 * recoloured or reordered are never recreated.
	 *
	 * @param string                                                                    $taxonomy Board or status taxonomy.
	 * @param array<int, array{name: string, slug: string, meta: array<string, mixed>}> $terms    Terms to create, default first.
	 */
	private static function seed( string $taxonomy, array $terms ): void {
		$default = RequestTerms::default_term( $taxonomy );
		if ( $default && term_exists( $default, $taxonomy ) ) {
			return;
		}

		// Terms exist but the default was lost. Use the oldest term rather than adding new ones.
		if ( RequestTerms::promote_oldest_term( $taxonomy ) ) {
			return;
		}

		$created = [];
		foreach ( $terms as $term ) {
			$result = wp_insert_term( $term['name'], $taxonomy, [ 'slug' => $term['slug'] ] );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			foreach ( $term['meta'] as $key => $value ) {
				update_term_meta( (int) $result['term_id'], $key, $value );
			}
			$created[] = (int) $result['term_id'];
		}

		if ( $created ) {
			RequestTerms::set_default_term( $taxonomy, $created[0] );
		}
	}

	/**
	 * Claims the install lock so that two requests never install at the same time.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;

		// INSERT IGNORE is atomic: when two requests race, only one creates the row.
		$created = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock must bypass the options cache.
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				$wpdb->options,
				self::LOCK_OPTION,
				(string) time(),
				'off'
			)
		);
		if ( $created ) {
			return true;
		}

		$locked_at = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock must bypass the options cache.
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION )
		);
		if ( $locked_at > time() - self::LOCK_TIMEOUT ) {
			return false;
		}

		// The request holding the lock died mid-install. Take over.
		$wpdb->update( $wpdb->options, [ 'option_value' => (string) time() ], [ 'option_name' => self::LOCK_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock must bypass the options cache.

		return true;
	}

	/**
	 * Releases the install lock.
	 */
	private static function release_lock(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock must bypass the options cache.
	}
}
