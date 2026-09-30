<?php
/**
 * Uninstall tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\ContentTypes;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Install\Uninstaller;
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
		$this->assertCount( 1, $drops );
		$this->assertStringContainsString( Schema::table( Schema::VOTES ), $drops[0] );
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
