<?php
/**
 * Tests of the 1.1 voting rules and access hooks on boards for visitors who confirm their email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Voting\VoteService;

/**
 * A confirmed guest is held to the board's vote limit, and the access filters see them as
 * someone the board allows. The board's pages stay the same for every visitor.
 */
final class VerifiedGuestRulesTest extends TestCase {

	use GuestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->forget_visitor();
		$this->board_rules( BoardSettings::VERIFIED, BoardSettings::VERIFIED );
	}

	public function tear_down(): void {
		$this->forget_visitor();
		remove_all_filters( VoteService::FILTER_CAN_VOTE );
		remove_all_filters( SubmissionService::FILTER_CAN_SUBMIT );
		parent::tear_down();
	}

	public function test_a_confirmed_guest_is_held_to_the_vote_limit(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::VOTE_LIMIT => 1 ] );
		$first  = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$second = $this->create_request( [ 'post_title' => 'Export' ] );
		$this->become_guest();

		$this->assertSame( 200, $this->rest_vote( 'POST', $first )->get_status() );
		$refused = $this->rest_vote( 'POST', $second );

		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( VoteService::ERROR_LIMIT_REACHED, $refused->get_data()['code'] );
	}

	public function test_the_vote_filter_hears_that_a_confirmed_guest_may_vote(): void {
		$request = $this->create_request();
		$heard   = [];
		add_filter(
			VoteService::FILTER_CAN_VOTE,
			static function ( bool $allowed ) use ( &$heard ): bool {
				$heard[] = $allowed;
				return $allowed;
			}
		);

		$this->assertSame( VoteService::ERROR_EMAIL_REQUIRED, VoteService::check( $request )->get_error_code(), 'A visitor who has not confirmed is asked for their email.' );
		$this->become_guest();
		$this->assertTrue( VoteService::check( $request ) );
		$this->assertSame( [ false, true ], $heard );
	}

	public function test_the_vote_filter_can_refuse_a_confirmed_guest(): void {
		$request = $this->create_request();
		$this->become_guest();
		add_filter( VoteService::FILTER_CAN_VOTE, '__return_false' );

		$this->assertSame( VoteService::ERROR_NOT_ALLOWED, VoteService::check( $request )->get_error_code() );
	}

	public function test_the_board_takes_requests_from_any_visitor_who_will_confirm(): void {
		$this->assertTrue( SubmissionService::check( $this->default_board() ) );

		$html = SubmitForm::render( [ 'board' => $this->default_board() ], '' );
		$this->assertStringContainsString( '<form', $html, 'The form is shown, not the message for a visitor the filter refused.' );
	}

	public function test_the_submit_filter_can_still_refuse_visitors(): void {
		add_filter( SubmissionService::FILTER_CAN_SUBMIT, '__return_false' );

		$this->assertSame( SubmissionService::ERROR_NOT_ALLOWED, SubmissionService::check( $this->default_board() )->get_error_code() );
	}
}
