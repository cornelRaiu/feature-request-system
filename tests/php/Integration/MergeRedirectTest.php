<?php
/**
 * Merged request address tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Merging\MergeService;
use RuntimeException;

/**
 * The address of a merged request leads, with a permanent redirect, to the request it
 * was merged into, for everyone and in every form of the address.
 */
final class MergeRedirectTest extends TestCase {

	/**
	 * The merged request.
	 *
	 * @var int
	 */
	private $duplicate = 0;

	/**
	 * The request it was merged into.
	 *
	 * @var int
	 */
	private $target = 0;

	/**
	 * The merged request's address from before the merge, as links elsewhere have it.
	 *
	 * @var string
	 */
	private $old_url = '';

	public function set_up(): void {
		parent::set_up();
		// Post types add their rewrite rules at registration, so register again with pretty permalinks on.
		$this->set_permalink_structure( '/%postname%/' );
		RequestPostType::register();
		flush_rewrite_rules( false );

		$this->duplicate = $this->create_request( [ 'post_title' => 'Night theme' ] );
		$this->target    = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->old_url   = (string) get_permalink( $this->duplicate );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		MergeService::merge( $this->duplicate, $this->target );
		wp_set_current_user( 0 );
	}

	public function test_a_visitor_who_opens_a_merged_request_lands_on_the_request_it_was_merged_into(): void {
		$this->assertSame( home_url( '/' . RequestPostType::REWRITE_SLUG . '/night-theme/' ), $this->old_url );

		$this->assertSame( [ get_permalink( $this->target ), 301 ], $this->open( $this->old_url ) );
	}

	public function test_the_plain_addresses_lead_there_too(): void {
		// WordPress gives a page that is not public its plain address.
		$this->assertSame( home_url( '/?post_type=' . RequestPostType::NAME . '&p=' . $this->duplicate ), get_permalink( $this->duplicate ) );

		$this->assertSame( [ get_permalink( $this->target ), 301 ], $this->open( get_permalink( $this->duplicate ) ) );
		$this->assertSame( [ get_permalink( $this->target ), 301 ], $this->open( home_url( '/?' . RequestPostType::NAME . '=night-theme' ) ) );
	}

	public function test_moderators_are_redirected_as_well(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( [ get_permalink( $this->target ), 301 ], $this->open( $this->old_url ) );
	}

	public function test_a_request_merged_into_one_that_was_merged_later_leads_to_the_last_one(): void {
		$last = $this->create_request( [ 'post_title' => 'Dark colours' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		MergeService::merge( $this->target, $last );
		wp_set_current_user( 0 );

		$this->assertSame( [ get_permalink( $last ), 301 ], $this->open( $this->old_url ) );
	}

	public function test_nothing_is_shown_when_the_request_it_went_to_is_no_longer_published(): void {
		wp_trash_post( $this->target );

		$this->assertSame( [], $this->open( $this->old_url ) );
		$this->assertTrue( is_404() );
	}

	public function test_other_pages_are_left_alone(): void {
		$this->assertSame( [], $this->open( get_permalink( $this->target ) ) );
		$this->assertTrue( is_singular() );

		$this->assertSame( [], $this->open( home_url( '/feature-requests/no-such-request/' ) ) );
		$this->assertTrue( is_404() );
	}

	/**
	 * Opens a page as WordPress does, and returns where it redirects to and with which
	 * status, or an empty array when it shows the page.
	 *
	 * @param string $url Address of the page.
	 * @return array{0?: string, 1?: int}
	 */
	private function open( string $url ): array {
		$this->go_to( $url );

		$redirect = static function ( string $location, int $status ) {
			throw new RuntimeException( $location . ' ' . $status );
		};
		add_filter( 'wp_redirect', $redirect, 10, 2 );
		// Only this plugin's redirect: WordPress's own guesses for missing pages are not under test.
		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wp_old_slug_redirect' );

		try {
			do_action( 'template_redirect' );
		} catch ( RuntimeException $redirected ) {
			list( $location, $status ) = explode( ' ', $redirected->getMessage() );

			return [ $location, (int) $status ];
		} finally {
			remove_filter( 'wp_redirect', $redirect );
		}

		return [];
	}
}
