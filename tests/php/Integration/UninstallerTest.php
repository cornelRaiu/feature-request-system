<?php
/**
 * Uninstall tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use ActionScheduler;
use ActionScheduler_Store;
use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\ContentTypes;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Install\Uninstaller;
use FeatureRequestSystem\Notifications\Queue;
use FeatureRequestSystem\Notifications\StatusNotifier;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Security\RateLimiter;
use FeatureRequestSystem\Settings;

/**
 * Uninstalling removes plugin data only when the site opted in.
 *
 * The test suite turns DROP TABLE into DROP TEMPORARY TABLE to keep the real table,
 * so these tests check that the drop query was sent rather than that the table is gone.
 */
final class UninstallerTest extends TestCase {

	/**
	 * Queries sent during the test.
	 *
	 * @var string[]
	 */
	private $queries = [];

	public function set_up(): void {
		parent::set_up();
		add_filter( 'query', [ $this, 'record_query' ], 99 );
	}

	/**
	 * Records a query and passes it through unchanged.
	 *
	 * @param string $query SQL query.
	 */
	public function record_query( string $query ): string {
		$this->queries[] = $query;
		return $query;
	}

	public function test_uninstall_keeps_everything_without_opt_in(): void {
		$request = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );

		Uninstaller::run();

		$this->assertNotNull( get_post( $request ) );
		$this->assertCount( 6, get_terms( [ 'taxonomy' => StatusTaxonomy::NAME, 'hide_empty' => false ] ) );
		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::OPTION_DB_VERSION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_SETTINGS ) );
		$this->assertSame( [], $this->drop_queries() );
	}

	public function test_uninstall_removes_all_plugin_data_with_opt_in(): void {
		Settings::update( Settings::DELETE_DATA_ON_UNINSTALL, true );
		$published = self::factory()->post->create( [ 'post_type' => RequestPostType::NAME ] );
		$trashed   = self::factory()->post->create(
			[
				'post_type'   => RequestPostType::NAME,
				'post_status' => 'trash',
			]
		);
		$comment   = self::factory()->comment->create( [ 'comment_post_ID' => $published ] );
		self::factory()->term->create( [ 'taxonomy' => CategoryTaxonomy::NAME ] );
		$unrelated = self::factory()->post->create();
		set_transient( RateLimiter::TRANSIENT_PREFIX . 'vote_ip_0123456789abcdef_2026100112', 3, HOUR_IN_SECONDS );
		$user = self::factory()->user->create();
		Subscriptions::set_automatic( $user, false );
		Queue::schedule( time() + HOUR_IN_SECONDS, StatusNotifier::HOOK, [ $published, 'notice', 1, 50 ] );
		$theirs = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'another_plugins_hook', [], 'another-plugin' );

		Uninstaller::run();

		$this->assertNull( get_post( $published ) );
		$this->assertNull( get_post( $trashed ) );
		$this->assertNull( get_comment( $comment ) );
		$this->assertNotNull( get_post( $unrelated ), 'Other content is untouched.' );

		foreach ( ContentTypes::taxonomies() as $taxonomy ) {
			$this->assertSame( [], get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] ), $taxonomy );
		}

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Installer::OPTION_DB_VERSION ) );

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_merge( Capabilities::REQUEST_CAPS, [ Capabilities::MANAGE_SETTINGS ] ) as $cap ) {
				$this->assertFalse( $role->has_cap( $cap ), "{$role->name}: {$cap}" );
			}
		}

		$drops = $this->drop_queries();
		$this->assertCount( 2, $drops );
		$this->assertStringContainsString( Schema::table( Schema::VOTES ), $drops[0] );
		$this->assertStringContainsString( Schema::table( Schema::SUBSCRIPTIONS ), $drops[1] );

		$this->assertSame( '', get_user_meta( $user, Subscriptions::META_AUTO_FOLLOW, true ), "A user's choice about following is removed." );
		$this->assertFalse( as_has_scheduled_action( StatusNotifier::HOOK ), 'Emails that were waiting are cancelled.' );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $theirs ), "Another plugin's background work is untouched." );

		global $wpdb;
		$counters = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				'%' . $wpdb->esc_like( RateLimiter::TRANSIENT_PREFIX ) . '%'
			)
		);
		$this->assertSame( '0', $counters, 'Rate limit counters are removed.' );
	}

	/**
	 * Returns the DROP queries sent during the test.
	 *
	 * @return string[]
	 */
	private function drop_queries(): array {
		return array_values(
			array_filter(
				$this->queries,
				static function ( string $query ): bool {
					return 0 === stripos( ltrim( $query ), 'DROP' );
				}
			)
		);
	}
}
