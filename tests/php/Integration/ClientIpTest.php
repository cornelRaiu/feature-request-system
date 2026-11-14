<?php
/**
 * Client IP address tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Security\ClientIp;

/**
 * The visitor's address: REMOTE_ADDR, or the one Cloudflare passes on when the connection
 * really comes from Cloudflare.
 */
final class ClientIpTest extends TestCase {

	public function tear_down(): void {
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		parent::tear_down();
	}

	public function test_the_address_is_the_one_that_reached_the_server(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$this->assertSame( '203.0.113.10', ClientIp::get() );
	}

	public function test_a_visitor_cannot_claim_another_address_through_cloudflare_s_header(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

		$this->assertSame( '203.0.113.10', ClientIp::get() );
	}

	public function test_behind_cloudflare_the_address_is_the_visitor_s(): void {
		$_SERVER['REMOTE_ADDR']           = '162.158.1.2';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$this->assertSame( '198.51.100.7', ClientIp::get() );

		$_SERVER['REMOTE_ADDR']           = '2606:4700:10::6816:1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::7';
		$this->assertSame( '2001:db8::7', ClientIp::get(), 'Over IPv6.' );

		$_SERVER['REMOTE_ADDR']           = '::ffff:162.158.1.2';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.8';
		$this->assertSame( '198.51.100.8', ClientIp::get(), 'A Cloudflare IPv4 address written as IPv6.' );
	}

	public function test_behind_cloudflare_without_a_valid_visitor_address_cloudflare_s_is_used(): void {
		$_SERVER['REMOTE_ADDR'] = '162.158.1.2';
		$this->assertSame( '162.158.1.2', ClientIp::get(), 'No header.' );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7, 203.0.113.1';
		$this->assertSame( '162.158.1.2', ClientIp::get(), 'Not one address.' );
	}

	/**
	 * @dataProvider cloudflare_range_edges
	 *
	 * @param string $ip       Address.
	 * @param bool   $expected Whether it is Cloudflare's.
	 */
	public function test_cloudflare_s_ranges_end_where_they_should( string $ip, bool $expected ): void {
		$this->assertSame( $expected, ClientIp::from_cloudflare( $ip ) );
	}

	/**
	 * The first and last address of ranges whose length is not a whole number of bytes, and their neighbours.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function cloudflare_range_edges(): array {
		return [
			'before 162.158.0.0/15'   => [ '162.157.255.255', false ],
			'first of 162.158.0.0/15' => [ '162.158.0.0', true ],
			'last of 162.158.0.0/15'  => [ '162.159.255.255', true ],
			'after 162.158.0.0/15'    => [ '162.160.0.0', false ],
			'first of 172.64.0.0/13'  => [ '172.64.0.0', true ],
			'last of 172.64.0.0/13'   => [ '172.71.255.255', true ],
			'after 172.64.0.0/13'     => [ '172.72.0.0', false ],
			'last of 173.245.48.0/20' => [ '173.245.63.255', true ],
			'after 173.245.48.0/20'   => [ '173.245.64.0', false ],
			'first of 2a06:98c0::/29' => [ '2a06:98c0::', true ],
			'last of 2a06:98c0::/29'  => [ '2a06:98c7:ffff:ffff:ffff:ffff:ffff:ffff', true ],
			'after 2a06:98c0::/29'    => [ '2a06:98c8::', false ],
			'before 2606:4700::/32'   => [ '2606:46ff:ffff:ffff:ffff:ffff:ffff:ffff', false ],
			'a private address'       => [ '10.0.0.1', false ],
			'not an address'          => [ 'unknown', false ],
			'empty'                   => [ '', false ],
		];
	}

	public function test_a_site_can_stop_reading_cloudflare_s_header(): void {
		$_SERVER['REMOTE_ADDR']           = '162.158.1.2';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		add_filter( ClientIp::FILTER_TRUST_CLOUDFLARE, '__return_false' );

		$this->assertSame( '162.158.1.2', ClientIp::get() );
	}

	public function test_the_site_s_filter_gets_the_visitor_s_address_and_has_the_last_word(): void {
		$_SERVER['REMOTE_ADDR']           = '162.158.1.2';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$seen                             = '';
		add_filter(
			ClientIp::FILTER,
			static function ( string $ip ) use ( &$seen ): string {
				$seen = $ip;
				return '192.0.2.44';
			}
		);

		$this->assertSame( '192.0.2.44', ClientIp::get() );
		$this->assertSame( '198.51.100.7', $seen );
	}

	public function test_without_a_valid_address_there_is_none(): void {
		$_SERVER['REMOTE_ADDR'] = 'unknown';
		$this->assertSame( '', ClientIp::get() );

		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '', ClientIp::get(), 'No REMOTE_ADDR at all.' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		add_filter(
			ClientIp::FILTER,
			static function (): string {
				return 'not an address';
			}
		);
		$this->assertSame( '', ClientIp::get(), 'The filter returned something else.' );
	}
}
