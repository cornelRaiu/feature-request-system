<?php
/**
 * Same-site check tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Security\RequestOrigin;

/**
 * The test site lives at http://example.org.
 */
final class RequestOriginTest extends TestCase {

	/**
	 * Origin and Referer combinations, and whether each counts as this site.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function origins(): array {
		return [
			'same origin'                    => [ 'http://example.org', '', true ],
			'same host over https'           => [ 'https://example.org', '', true ],
			'upper-case origin'              => [ 'HTTP://EXAMPLE.ORG', '', true ],
			'another site'                   => [ 'https://evil.example', '', false ],
			'subdomain'                      => [ 'http://sub.example.org', '', false ],
			'look-alike host'                => [ 'http://example.org.evil.example', '', false ],
			'same host on another port'      => [ 'http://example.org:8080', '', false ],
			'referer on this site'           => [ '', 'http://example.org/roadmap/?sort=top', true ],
			'referer on another site'        => [ '', 'https://evil.example/page', false ],
			'origin is checked before referer' => [ 'https://evil.example', 'http://example.org/', false ],
			'opaque origin'                  => [ 'null', 'http://example.org/', false ],
			'no headers'                     => [ '', '', false ],
		];
	}

	/**
	 * @dataProvider origins
	 *
	 * @param string $origin   Origin header.
	 * @param string $referer  Referer header.
	 * @param bool   $expected Whether it counts as this site.
	 */
	public function test_same_site_check( string $origin, string $referer, bool $expected ): void {
		$this->assertSame( $expected, RequestOrigin::is_same_site( $origin, $referer ) );
	}

	public function test_origins_added_through_the_core_filter_count_as_this_site(): void {
		add_filter(
			'allowed_http_origins',
			static function ( array $origins ): array {
				$origins[] = 'https://app.example.com';
				return $origins;
			}
		);

		$this->assertTrue( RequestOrigin::is_same_site( 'https://app.example.com', '' ) );
	}
}
