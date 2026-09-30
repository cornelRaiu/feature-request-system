<?php
/**
 * Release metadata consistency.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Unit;

use FeatureRequestSystem\Plugin;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The version and requirements must agree across the code, the plugin header and the readme.
 */
final class ReleaseMetadataTest extends TestCase {

	private const ROOT = __DIR__ . '/../../..';

	public function test_version_matches_in_code_header_and_readme(): void {
		$header = get_file_data( self::ROOT . '/feature-request-system.php', [ 'version' => 'Version' ] );
		$readme = get_file_data( self::ROOT . '/readme.txt', [ 'stable' => 'Stable tag' ] );

		$this->assertSame( Plugin::VERSION, $header['version'] );
		$this->assertSame( Plugin::VERSION, $readme['stable'] );
	}

	public function test_requirements_match_in_header_and_readme(): void {
		$fields = [
			'wp'  => 'Requires at least',
			'php' => 'Requires PHP',
		];

		$this->assertSame(
			get_file_data( self::ROOT . '/feature-request-system.php', $fields ),
			get_file_data( self::ROOT . '/readme.txt', $fields )
		);
	}

	public function test_text_domain_is_the_plugin_slug(): void {
		$header = get_file_data( self::ROOT . '/feature-request-system.php', [ 'domain' => 'Text Domain' ] );

		$this->assertSame( 'feature-request-system', $header['domain'] );
	}
}
