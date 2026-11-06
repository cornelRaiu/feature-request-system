<?php
/**
 * Guests table access.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Guests;

use FeatureRequestSystem\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the guests table. One row per email address, enforced by a
 * unique key.
 *
 * @phpstan-type Guest array{id: int, email: string, name: string, token_hash: string, link_sent_at: string, pending: list<array<string, mixed>>, auto_follow: bool, confirmed_at: string, created_at: string}
 */
final class GuestRepository {

	/**
	 * Columns read for a guest.
	 */
	private const COLUMNS = 'id, email, name, token_hash, link_sent_at, pending, auto_follow, confirmed_at, created_at';

	/**
	 * Returns a guest by ID, or null.
	 *
	 * @param int $guest_id Guest ID.
	 * @return array<string, mixed>|null
	 * @phpstan-return Guest|null
	 */
	public static function find( int $guest_id ): ?array {
		global $wpdb;

		if ( $guest_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; who a visitor is must not be cached.
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', Schema::table( Schema::GUESTS ), $guest_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
		);

		return self::guest( $row );
	}

	/**
	 * Returns the guest with an email address, or null.
	 *
	 * The database compares addresses without regard to case, and its key covers the
	 * first 191 characters only. The address must match in full.
	 *
	 * @param string $email Email address, in lower case.
	 * @return array<string, mixed>|null
	 * @phpstan-return Guest|null
	 */
	public static function find_by_email( string $email ): ?array {
		global $wpdb;

		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; per-visitor data must not be cached.
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE email = %s', Schema::table( Schema::GUESTS ), $email ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
		);
		$guest = self::guest( $row );

		return $guest && $guest['email'] === $email ? $guest : null;
	}

	/**
	 * Returns the guest whose confirmation link has this token, or null.
	 *
	 * @param string $token_hash SHA-256 hash of the token in the link.
	 * @return array<string, mixed>|null
	 * @phpstan-return Guest|null
	 */
	public static function find_by_token_hash( string $token_hash ): ?array {
		global $wpdb;

		if ( '' === $token_hash ) {
			return null;
		}

		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; a secret must not be cached.
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE token_hash = %s', Schema::table( Schema::GUESTS ), $token_hash ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The columns are a constant.
		);
		$guest = self::guest( $row );

		return $guest && hash_equals( $guest['token_hash'], $token_hash ) ? $guest : null;
	}

	/**
	 * Returns the guest with an email address, adding one when there is none.
	 *
	 * @param string $email Email address, in lower case.
	 * @return array<string, mixed>|null Null only when another address shares the first 191 characters.
	 * @phpstan-return Guest|null
	 */
	public static function find_or_create( string $email ): ?array {
		global $wpdb;

		// The unique key makes two requests that add the same address at once end with one row.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (email, created_at) VALUES (%s, %s)',
				Schema::table( Schema::GUESTS ),
				$email,
				current_time( 'mysql', true )
			)
		);

		return self::find_by_email( $email );
	}

	/**
	 * Stores a new link token for a guest, unless a link was sent less than $interval seconds ago.
	 *
	 * One statement checks and writes, so requests that arrive together send one email.
	 *
	 * @param int    $guest_id   Guest ID.
	 * @param string $token_hash SHA-256 hash of the new token.
	 * @param int    $interval   Fewest seconds between two links.
	 * @return bool Whether the new token was stored, and its link may be sent.
	 */
	public static function claim_link( int $guest_id, string $token_hash, int $interval ): bool {
		global $wpdb;

		$now = time();

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'UPDATE %i SET token_hash = %s, link_sent_at = %s WHERE id = %d AND ( link_sent_at IS NULL OR link_sent_at <= %s )',
				Schema::table( Schema::GUESTS ),
				$token_hash,
				gmdate( 'Y-m-d H:i:s', $now ),
				$guest_id,
				gmdate( 'Y-m-d H:i:s', $now - $interval )
			)
		);
	}

	/**
	 * Stores what a guest asked to do once their address is confirmed.
	 *
	 * @param int                        $guest_id Guest ID.
	 * @param list<array<string, mixed>> $pending  Actions, oldest first.
	 */
	public static function set_pending( int $guest_id, array $pending ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			Schema::table( Schema::GUESTS ),
			[ 'pending' => (string) wp_json_encode( $pending ) ],
			[ 'id' => $guest_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Marks a guest's address as confirmed through their link, and makes the link stop working.
	 *
	 * Only one of several requests that press the button at once succeeds, because the
	 * link's token has to be unchanged.
	 *
	 * @param int    $guest_id   Guest ID.
	 * @param string $token_hash Hash of the token in the link that was used.
	 * @param string $name       Name the guest gave, or '' to keep the stored one.
	 * @return bool Whether this request confirmed the link.
	 */
	public static function confirm_link( int $guest_id, string $token_hash, string $name ): bool {
		global $wpdb;

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				"UPDATE %i SET token_hash = '', pending = NULL, confirmed_at = COALESCE( confirmed_at, %s ), name = IF( %s = '', name, %s ) WHERE id = %d AND token_hash = %s",
				Schema::table( Schema::GUESTS ),
				current_time( 'mysql', true ),
				$name,
				$name,
				$guest_id,
				$token_hash
			)
		);
	}

	/**
	 * Marks a guest's address as confirmed without a link, as for a signed link of another plugin.
	 *
	 * @param int    $guest_id Guest ID.
	 * @param string $name     Name of the guest, or '' to keep the stored one.
	 */
	public static function confirm( int $guest_id, string $name ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				"UPDATE %i SET confirmed_at = COALESCE( confirmed_at, %s ), name = IF( %s = '', name, %s ) WHERE id = %d",
				Schema::table( Schema::GUESTS ),
				current_time( 'mysql', true ),
				$name,
				$name,
				$guest_id
			)
		);
	}

	/**
	 * Records whether a guest wants to follow the requests they vote for or suggest.
	 *
	 * @param int  $guest_id Guest ID.
	 * @param bool $on       Whether the guest wants that.
	 */
	public static function set_auto_follow( int $guest_id, bool $on ): void {
		global $wpdb;

		$wpdb->update( Schema::table( Schema::GUESTS ), [ 'auto_follow' => (int) $on ], [ 'id' => $guest_id ], [ '%d' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Deletes a guest.
	 *
	 * @param int $guest_id Guest ID.
	 * @return bool Whether a guest was deleted.
	 */
	public static function delete( int $guest_id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( Schema::GUESTS ), [ 'id' => $guest_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Turns a database row into a guest with typed fields.
	 *
	 * @param mixed $row Row from $wpdb->get_row().
	 * @return array<string, mixed>|null
	 * @phpstan-return Guest|null
	 */
	private static function guest( $row ): ?array {
		if ( ! is_object( $row ) ) {
			return null;
		}

		$pending = json_decode( (string) $row->pending, true );

		return [
			'id'           => (int) $row->id,
			'email'        => (string) $row->email,
			'name'         => (string) $row->name,
			'token_hash'   => (string) $row->token_hash,
			'link_sent_at' => (string) $row->link_sent_at,
			'pending'      => is_array( $pending ) ? array_values( array_filter( $pending, 'is_array' ) ) : [],
			'auto_follow'  => (bool) $row->auto_follow,
			'confirmed_at' => (string) $row->confirmed_at,
			'created_at'   => (string) $row->created_at,
		];
	}
}
