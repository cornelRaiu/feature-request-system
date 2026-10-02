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
	 * Subscriptions table without the site prefix. One row per user per request they follow.
	 */
	public const SUBSCRIPTIONS = 'mwtfrs_subscriptions';

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
	 * In the votes table, voter_key holds a SHA-256 hash of the voter (a user ID, or a
	 * random token for guests). The unique key on request_id and voter_key is what
	 * enforces one vote per voter per request.
	 *
	 * In the subscriptions table, token is the secret in a subscription's unsubscribe
	 * link, and source says how the user came to follow the request.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$votes         = self::table( self::VOTES );
		$subscriptions = self::table( self::SUBSCRIPTIONS );
		$collate       = $wpdb->get_charset_collate();

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

		dbDelta(
			"CREATE TABLE {$subscriptions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  token char(32) NOT NULL,
  source varchar(20) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY request_user (request_id,user_id),
  KEY token (token),
  KEY user_id (user_id)
) {$collate};"
		);
	}

	/**
	 * Drops the plugin's tables.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( [ self::VOTES, self::SUBSCRIPTIONS ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own tables.
		}
	}
}
