<?php
/**
 * Voting without JavaScript tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\RequestItem;
use FeatureRequestSystem\Frontend\RequestNotice;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Voting\VoterIdentity;

/**
 * The vote button's plain form post toggles the vote and sends the visitor back with a notice.
 */
final class VoteFormTest extends TestCase {

	private const BOARD_URL = 'http://example.org/roadmap/';

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_GET = [];
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_GET = [];
		parent::tear_down();
	}

	public function test_logged_in_user_toggles_their_vote_and_returns_to_the_board(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request();

		$first = VoteForm::process( $this->input( $request, wp_create_nonce( VoteForm::ACTION ) ), '', '' );
		$this->assertSame( self::BOARD_URL . '?fr-notice=added&fr-request=' . $request . '#' . RequestItem::dom_id( $request ), $first );
		$this->assertSame( '1', get_post_meta( $request, RequestPostType::META_VOTES, true ) );

		$second = VoteForm::process( $this->input( $request, wp_create_nonce( VoteForm::ACTION ) ), '', '' );
		$this->assertStringContainsString( 'fr-notice=removed', $second );
		$this->assertSame( '0', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	/**
	 * A page can show a request more than once, such as on a list and on a card. The form
	 * of each names its own element.
	 */
	public function test_the_vote_comes_back_to_the_element_that_the_form_named(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request              = $this->create_request();
		$element              = '#mwtfrs-list-2-request-' . $request;
		$input                = $this->input( $request, wp_create_nonce( VoteForm::ACTION ) );
		$input['redirect_to'] = self::BOARD_URL . '?fr-status=planned' . $element;

		$this->assertSame( self::BOARD_URL . '?fr-status=planned&fr-notice=added&fr-request=' . $request . $element, VoteForm::process( $input, '', '' ) );
	}

	public function test_logged_in_vote_with_a_bad_nonce_is_refused(): void {
		wp_set_current_user( self::factory()->user->create() );
		$request = $this->create_request();

		$this->assertStringContainsString( 'fr-notice=expired', VoteForm::process( $this->input( $request, 'forged' ), '', '' ) );
		$this->assertSame( '0', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	public function test_guest_form_posts_must_come_from_this_site(): void {
		$this->allow_guests();
		$request = $this->create_request();

		$this->assertStringContainsString( 'fr-notice=origin', VoteForm::process( $this->input( $request ), 'https://evil.example', '' ) );
		$this->assertStringContainsString( 'fr-notice=added', VoteForm::process( $this->input( $request ), 'http://example.org', '' ) );
		$this->assertSame( '1', get_post_meta( $request, RequestPostType::META_VOTES, true ) );
	}

	public function test_voting_errors_become_notices(): void {
		$request = $this->create_request();
		$this->assertStringContainsString( 'fr-notice=login', VoteForm::process( $this->input( $request ), 'http://example.org', '' ) );

		wp_set_current_user( self::factory()->user->create() );
		wp_set_object_terms( $request, $this->status( 'shipped' ), StatusTaxonomy::NAME );
		$this->assertStringContainsString( 'fr-notice=closed', VoteForm::process( $this->input( $request, wp_create_nonce( VoteForm::ACTION ) ), '', '' ) );
	}

	public function test_redirects_never_leave_the_site(): void {
		wp_set_current_user( self::factory()->user->create() );
		$input                = $this->input( $this->create_request(), wp_create_nonce( VoteForm::ACTION ) );
		$input['redirect_to'] = 'https://evil.example/phish';

		$this->assertStringStartsWith( home_url( '/' ), VoteForm::process( $input, '', '' ) );
	}

	public function test_notice_is_read_from_the_url_only_when_known(): void {
		$_GET = [
			RequestNotice::PARAM  => 'added',
			RequestNotice::PARAM_REQUEST => '12',
		];
		$this->assertSame(
			[
				'request' => 12,
				'message' => 'Your vote was added.',
				'login'   => false,
			],
			RequestNotice::from_request()
		);

		$_GET[ RequestNotice::PARAM ] = 'made-up';
		$this->assertNull( RequestNotice::from_request() );
	}

	/**
	 * Builds the fields the vote form posts.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $nonce      Nonce, which the form only includes for logged-in users.
	 * @return array<string, string>
	 */
	private function input( int $request_id, string $nonce = '' ): array {
		return array_filter(
			[
				'action'      => VoteForm::ACTION,
				'request_id'  => (string) $request_id,
				'redirect_to' => self::BOARD_URL,
				'_wpnonce'    => $nonce,
			]
		);
	}

	/**
	 * Lets guests vote on the default board.
	 */
	private function allow_guests(): void {
		BoardSettings::update( RequestTerms::default_term( BoardTaxonomy::NAME ), [ BoardSettings::VOTING => BoardSettings::ANYONE ] );
	}
}
