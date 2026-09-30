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
