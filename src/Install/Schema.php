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
	 * Guests table without the site prefix. One row per email address of a visitor who
	 * asked to confirm it, to vote or suggest features without an account. Database version 3.
	 */
	public const GUESTS = 'mwtfrs_guests';

	/**
	 * Name of the subscriptions table's unique key before database version 3, when it
	 * covered request_id and user_id only.
	 */
	private const OLD_SUBSCRIPTIONS_KEY = 'request_user';

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
	 * link, and source says how the user came to follow the request. A row belongs to a
	 * user, or to a guest who confirmed their email (guest_id), never to both.
	 *
	 * In the guests table, token_hash is a SHA-256 hash of the secret in the link that
	 * confirms the address; the secret itself is only in the email. link_sent_at says when
	 * that link was sent, pending holds what the visitor asked to do once the address is
	 * confirmed, and auto_follow is 0 for a guest who stopped every email.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$votes         = self::table( self::VOTES );
		$subscriptions = self::table( self::SUBSCRIPTIONS );
		$guests        = self::table( self::GUESTS );
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
  guest_id bigint(20) unsigned NOT NULL DEFAULT 0,
  token char(32) NOT NULL,
  source varchar(20) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY request_follower (request_id,user_id,guest_id),
  KEY token (token),
  KEY user_id (user_id),
  KEY guest_id (guest_id)
) {$collate};"
		);

		// An address is unique in its first 191 characters, the longest key every supported
		// database takes in utf8mb4. Guests looks addresses up in full.
		dbDelta(
			"CREATE TABLE {$guests} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(254) NOT NULL,
  name varchar(60) NOT NULL DEFAULT '',
  token_hash char(64) NOT NULL DEFAULT '',
  link_sent_at datetime DEFAULT NULL,
  pending longtext NULL,
  auto_follow tinyint(1) unsigned NOT NULL DEFAULT 1,
  confirmed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email(191)),
  KEY token_hash (token_hash)
) {$collate};"
		);

		self::drop_old_subscriptions_key();
	}

	/**
	 * Drops the subscriptions table's unique key from before database version 3.
	 *
	 * WordPress's dbDelta() adds keys but never drops one. The old key allowed one row per request
	 * with user_id 0, so it would refuse a second guest who follows the same request.
	 */
	private static function drop_old_subscriptions_key(): void {
		global $wpdb;

		$table = self::table( self::SUBSCRIPTIONS );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, self::OLD_SUBSCRIPTIONS_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the table's structure while upgrading it.

		if ( null !== $found ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, self::OLD_SUBSCRIPTIONS_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Upgrades the plugin's own table.
		}
	}

	/**
	 * Drops the plugin's tables.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( [ self::VOTES, self::SUBSCRIPTIONS, self::GUESTS ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own tables.
		}
	}
}
