<?php
/**
 * Custom database tables.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and drops the plugin's custom tables.
 */
final class Schema {

	/**
	 * Votes table without the site prefix. One row per voter per request.
	 */
	public const VOTES = 'mwtfrs_votes';

	/**
	 * Returns a table name with the current site's prefix.
	 *
	 * @param string $name Table name without prefix, one of this class's constants.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * Creates the tables, or adds missing columns and indexes to existing ones.
	 *
	 * The voter_key column holds a SHA-256 hash of the voter (a user ID, or a random
	 * token for guests). The unique key on request_id and voter_key is what enforces
	 * one vote per voter per request.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$votes   = self::table( self::VOTES );
		$collate = $wpdb->get_charset_collate();

		// dbDelta() needs each column on its own line and two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$votes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id bigint(20) unsigned NOT NULL,
  voter_key char(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  vote tinyint(4) NOT NULL DEFAULT 1,
  weight smallint(5) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY request_voter (request_id,voter_key),
  KEY user_id (user_id),
  KEY request_created (request_id,created_at)
) {$collate};"
		);
	}

	/**
	 * Drops the plugin's tables.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( self::VOTES ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own table.
	}
}
