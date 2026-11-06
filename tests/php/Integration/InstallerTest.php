<?php
/**
 * Installer tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Settings;

/**
 * The test site was installed by the plugin's init hook when the suite booted, the same
 * path a network-activated or file-copied site takes.
 */
final class InstallerTest extends TestCase {

	public function test_votes_table_has_the_planned_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col( 'DESCRIBE ' . Schema::table( Schema::VOTES ) );

		$this->assertSame( [ 'id', 'request_id', 'voter_key', 'user_id', 'vote', 'weight', 'created_at' ], $columns );
	}

	public function test_votes_table_rejects_a_second_vote_from_the_same_voter(): void {
		global $wpdb;

		$table = Schema::table( Schema::VOTES );
		$vote  = [
			'request_id' => 10,
			'voter_key'  => hash( 'sha256', 'u:1' ),
			'user_id'    => 1,
			'created_at' => '2026-09-30 12:00:00',
		];

		$this->assertSame( 1, $wpdb->insert( $table, $vote ) );

		$suppress = $wpdb->suppress_errors();
		$this->assertFalse( $wpdb->insert( $table, $vote ), 'Same voter, same request.' );
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 1, $wpdb->insert( $table, array_merge( $vote, [ 'request_id' => 11 ] ) ), 'Same voter, other request.' );
	}

	public function test_subscriptions_table_has_the_planned_columns_and_keys(): void {
		global $wpdb;

		$table = Schema::table( Schema::SUBSCRIPTIONS );

		// A site that had the table before database version 3 has guest_id at the end.
		$this->assertEqualsCanonicalizing( [ 'id', 'request_id', 'user_id', 'guest_id', 'token', 'source', 'created_at' ], $wpdb->get_col( 'DESCRIBE ' . $table ) );
		$this->assertEqualsCanonicalizing( [ 'PRIMARY', 'request_follower', 'token', 'user_id', 'guest_id' ], array_unique( $wpdb->get_col( 'SHOW INDEX FROM ' . $table, 2 ) ) );
	}

	public function test_subscriptions_table_holds_one_row_per_user_and_request(): void {
		global $wpdb;

		$table        = Schema::table( Schema::SUBSCRIPTIONS );
		$subscription = [
			'request_id' => 10,
			'user_id'    => 1,
			'token'      => str_repeat( 'a', 32 ),
			'source'     => 'vote',
			'created_at' => '2026-10-02 12:00:00',
		];

		$this->assertSame( 1, $wpdb->insert( $table, $subscription ) );

		$suppress = $wpdb->suppress_errors();
		$this->assertFalse( $wpdb->insert( $table, $subscription ), 'Same user, same request.' );
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 1, $wpdb->insert( $table, array_merge( $subscription, [ 'request_id' => 11 ] ) ), 'Same user, other request.' );
		$this->assertSame( 1, $wpdb->insert( $table, array_merge( $subscription, [ 'user_id' => 2 ] ) ), 'Other user, same request.' );
	}

	public function test_subscriptions_table_holds_users_and_any_number_of_guests_on_one_request(): void {
		global $wpdb;

		$table = Schema::table( Schema::SUBSCRIPTIONS );
		$row   = [
			'request_id' => 10,
			'user_id'    => 0,
			'guest_id'   => 5,
			'token'      => str_repeat( 'b', 32 ),
			'source'     => 'vote',
			'created_at' => '2026-11-06 12:00:00',
		];

		$this->assertSame( 1, $wpdb->insert( $table, $row ) );
		$this->assertSame( 1, $wpdb->insert( $table, array_merge( $row, [ 'guest_id' => 6 ] ) ), 'Another guest, same request.' );
		$this->assertSame( 1, $wpdb->insert( $table, array_merge( $row, [ 'guest_id' => 0, 'user_id' => 1 ] ) ), 'A user, same request.' );

		$suppress = $wpdb->suppress_errors();
		$this->assertFalse( $wpdb->insert( $table, $row ), 'Same guest, same request.' );
		$wpdb->suppress_errors( $suppress );
	}

	public function test_guests_table_has_the_planned_columns_and_one_row_per_address(): void {
		global $wpdb;

		$table = Schema::table( Schema::GUESTS );

		$this->assertSame( [ 'id', 'email', 'name', 'token_hash', 'link_sent_at', 'pending', 'auto_follow', 'confirmed_at', 'created_at' ], $wpdb->get_col( 'DESCRIBE ' . $table ) );
		$this->assertEqualsCanonicalizing( [ 'PRIMARY', 'email', 'token_hash' ], array_unique( $wpdb->get_col( 'SHOW INDEX FROM ' . $table, 2 ) ) );

		$this->assertSame( 1, $wpdb->insert( $table, [ 'email' => 'jane@example.com', 'created_at' => '2026-11-06 12:00:00' ] ) );
		$suppress = $wpdb->suppress_errors();
		$this->assertFalse( $wpdb->insert( $table, [ 'email' => 'JANE@example.com', 'created_at' => '2026-11-06 12:00:00' ] ), 'The same address in other letters.' );
		$wpdb->suppress_errors( $suppress );
		$this->assertSame( 1, $wpdb->insert( $table, [ 'email' => 'john@example.com', 'created_at' => '2026-11-06 12:00:00' ] ) );
	}

	/**
	 * Before database version 3 the subscriptions table's unique key covered the request
	 * and the user only, which would allow one guest per request.
	 *
	 * A temporary table of the old shape stands in for the site's table, which it hides
	 * while it exists.
	 */
	public function test_a_site_on_database_version_2_gets_the_key_that_lets_guests_follow(): void {
		global $wpdb;

		$table = Schema::table( Schema::SUBSCRIPTIONS );
		$wpdb->query(
			"CREATE TEMPORARY TABLE {$table} (
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
			) " . $wpdb->get_charset_collate()
		);
		$wpdb->insert( $table, [ 'request_id' => 10, 'user_id' => 1, 'token' => str_repeat( 'a', 32 ), 'source' => 'vote', 'created_at' => '2026-10-02 12:00:00' ] );

		Schema::create_tables();

		$keys = array_unique( $wpdb->get_col( 'SHOW INDEX FROM ' . $table, 2 ) );
		$rows = $wpdb->get_results( "SELECT user_id, guest_id FROM {$table}" );
		$this->assertContains( 'request_follower', $keys );
		$this->assertNotContains( 'request_user', $keys );
		$this->assertSame( '0', $rows[0]->guest_id, 'What users followed stays.' );

		$guest = [ 'request_id' => 10, 'user_id' => 0, 'token' => str_repeat( 'b', 32 ), 'source' => 'vote', 'created_at' => '2026-11-06 12:00:00' ];
		$this->assertSame( 1, $wpdb->insert( $table, array_merge( $guest, [ 'guest_id' => 5 ] ) ) );
		$this->assertSame( 1, $wpdb->insert( $table, array_merge( $guest, [ 'guest_id' => 6 ] ) ) );

		$wpdb->query( "DROP TEMPORARY TABLE {$table}" );
	}

	public function test_a_site_on_the_previous_database_version_gets_its_tables_brought_up_to_date(): void {
		// dbDelta() reads a table's columns, then creates the table or adds what is missing.
		$examined = [];
		add_filter(
			'query',
			static function ( string $query ) use ( &$examined ): string {
				if ( preg_match( '/^\s*DESCRIBE\s+(\S+?);?\s*$/i', $query, $match ) ) {
					$examined[] = trim( $match[1], '`' );
				}

				return $query;
			},
			99
		);

		Installer::maybe_upgrade();
		$this->assertSame( [], $examined, 'A site on the current version is left alone.' );

		update_option( Installer::OPTION_DB_VERSION, 1 );
		Installer::maybe_upgrade();

		$this->assertSame( [ Schema::table( Schema::VOTES ), Schema::table( Schema::SUBSCRIPTIONS ), Schema::table( Schema::GUESTS ) ], $examined );
		$this->assertSame( 3, Installer::DB_VERSION );
		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
	}

	public function test_install_seeds_six_statuses_in_workflow_order(): void {
		$statuses = StatusTaxonomy::sort(
			get_terms(
				[
					'taxonomy'   => StatusTaxonomy::NAME,
					'hide_empty' => false,
				]
			)
		);

		$this->assertSame( [ 'Open', 'Under Review', 'Planned', 'In Progress', 'Shipped', 'Declined' ], wp_list_pluck( $statuses, 'name' ) );
		$this->assertSame( 'open', get_term( RequestTerms::default_term( StatusTaxonomy::NAME ) )->slug );
	}

	public function test_seeded_statuses_carry_their_colour_and_flags(): void {
		$closed  = [];
		$roadmap = [];
		foreach ( get_terms( [ 'taxonomy' => StatusTaxonomy::NAME, 'hide_empty' => false ] ) as $status ) {
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', get_term_meta( $status->term_id, StatusTaxonomy::META_COLOR, true ) );
			if ( get_term_meta( $status->term_id, StatusTaxonomy::META_CLOSED, true ) ) {
				$closed[] = $status->slug;
			}
			if ( get_term_meta( $status->term_id, StatusTaxonomy::META_ROADMAP, true ) ) {
				$roadmap[] = $status->slug;
			}
		}

		$this->assertEqualsCanonicalizing( [ 'shipped', 'declined' ], $closed );
		$this->assertEqualsCanonicalizing( [ 'planned', 'in-progress', 'shipped' ], $roadmap );
	}

	public function test_install_seeds_one_default_board(): void {
		$boards = get_terms( [ 'taxonomy' => BoardTaxonomy::NAME, 'hide_empty' => false ] );

		$this->assertCount( 1, $boards );
		$this->assertSame( $boards[0]->term_id, RequestTerms::default_term( BoardTaxonomy::NAME ) );
	}

	public function test_install_grants_capabilities_to_administrators_and_editors_only(): void {
		$all_caps = array_merge( Capabilities::REQUEST_CAPS, [ Capabilities::MANAGE_SETTINGS ] );

		foreach ( Capabilities::REQUEST_CAPS as $cap ) {
			$this->assertTrue( get_role( 'administrator' )->has_cap( $cap ), "administrator: {$cap}" );
			$this->assertTrue( get_role( 'editor' )->has_cap( $cap ), "editor: {$cap}" );
		}
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE_SETTINGS ) );

		foreach ( [ 'author', 'contributor', 'subscriber' ] as $role ) {
			foreach ( $all_caps as $cap ) {
				$this->assertFalse( get_role( $role )->has_cap( $cap ), "{$role}: {$cap}" );
			}
		}
	}

	public function test_running_install_again_changes_nothing(): void {
		$before = $this->snapshot();

		Installer::install();
		Installer::install();

		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_install_keeps_a_renamed_default_status(): void {
		$default = RequestTerms::default_term( StatusTaxonomy::NAME );
		wp_update_term( $default, StatusTaxonomy::NAME, [ 'name' => 'New' ] );

		Installer::install();

		$this->assertSame( $default, RequestTerms::default_term( StatusTaxonomy::NAME ) );
		$this->assertSame( 'New', get_term( $default )->name );
		$this->assertCount( 6, get_terms( [ 'taxonomy' => StatusTaxonomy::NAME, 'hide_empty' => false ] ) );
	}

	public function test_install_recovers_a_lost_default_without_adding_terms(): void {
		Settings::update( Settings::DEFAULT_STATUS, 0 );

		Installer::install();

		$this->assertSame( $this->status( 'open' ), RequestTerms::default_term( StatusTaxonomy::NAME ) );
		$this->assertCount( 6, get_terms( [ 'taxonomy' => StatusTaxonomy::NAME, 'hide_empty' => false ] ) );
	}

	public function test_upgrade_installs_when_the_database_version_is_behind(): void {
		delete_option( Installer::OPTION_DB_VERSION );
		get_role( 'editor' )->remove_cap( Capabilities::REQUEST_CAPS[0] );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::REQUEST_CAPS[0] ) );
	}

	public function test_upgrade_does_nothing_when_the_database_is_current(): void {
		get_role( 'editor' )->remove_cap( Capabilities::REQUEST_CAPS[0] );

		Installer::maybe_upgrade();

		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::REQUEST_CAPS[0] ) );
	}

	public function test_install_waits_while_another_request_holds_the_lock(): void {
		$this->insert_lock( time() );
		delete_option( Installer::OPTION_DB_VERSION );

		Installer::maybe_upgrade();

		$this->assertFalse( get_option( Installer::OPTION_DB_VERSION ) );
	}

	public function test_install_takes_over_a_lock_left_by_a_crashed_request(): void {
		global $wpdb;

		$this->insert_lock( time() - HOUR_IN_SECONDS );
		delete_option( Installer::OPTION_DB_VERSION );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, Installer::LOCK_OPTION ) ) );
	}

	public function test_activation_installs_and_resets_rewrite_rules(): void {
		update_option( 'rewrite_rules', [ 'stale' => 'rules' ] );
		delete_option( Installer::OPTION_DB_VERSION );

		Installer::activate();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
		$this->assertEmpty( get_option( 'rewrite_rules' ) );
	}

	public function test_deactivation_keeps_data_and_capabilities(): void {
		Installer::deactivate();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::REQUEST_CAPS[0] ) );
		$this->assertNotSame( 0, RequestTerms::default_term( StatusTaxonomy::NAME ) );
	}

	/**
	 * Captures the state that installing must leave unchanged.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		return [
			'statuses' => get_terms( [ 'taxonomy' => StatusTaxonomy::NAME, 'hide_empty' => false, 'fields' => 'id=>name' ] ),
			'boards'   => get_terms( [ 'taxonomy' => BoardTaxonomy::NAME, 'hide_empty' => false, 'fields' => 'id=>name' ] ),
			'settings' => get_option( Settings::OPTION ),
			'version'  => (int) get_option( Installer::OPTION_DB_VERSION ),
		];
	}

	/**
	 * Writes the install lock row the way a concurrent request would.
	 *
	 * @param int $locked_at Unix time the other install started.
	 */
	private function insert_lock( int $locked_at ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => Installer::LOCK_OPTION,
				'option_value' => (string) $locked_at,
				'autoload'     => 'off',
			]
		);
	}
}
