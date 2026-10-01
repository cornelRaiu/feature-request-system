<?php
/**
 * Akismet spam check tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use Akismet;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Submission\SubmissionService;

/**
 * New requests go to Akismet when it is set up, and requests it flags wait for review.
 */
final class AkismetTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/Fakes/Akismet.php';
	}

	public function tear_down(): void {
		Akismet::reset();
		parent::tear_down();
	}

	public function test_nothing_is_sent_while_akismet_has_no_api_key(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->submit();

		$this->assertNull( Akismet::$last_query );
	}

	public function test_akismet_checks_the_request_as_a_forum_post(): void {
		Akismet::$api_key       = 'key';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		wp_set_current_user( self::factory()->user->create() );

		$request = $this->submit();

		$this->assertSame( 'forum-post', Akismet::$last_query['comment_type'] );
		$this->assertSame( '203.0.113.10', Akismet::$last_query['user_ip'] );
		$this->assertSame( "Export to CSV\n\nFor our accountant.", Akismet::$last_query['comment_content'] );
		$this->assertSame( '', get_post_meta( $request, RequestPostType::META_FLAGGED_AS_SPAM, true ) );
	}

	public function test_requests_akismet_flags_are_held_for_review(): void {
		Akismet::$api_key = 'key';
		Akismet::$verdict = 'true';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = $this->submit();

		$this->assertSame( 'pending', get_post_status( $request ), 'Held even for a moderator, whose requests are normally published.' );
		$this->assertSame( '1', get_post_meta( $request, RequestPostType::META_FLAGGED_AS_SPAM, true ) );
	}

	/**
	 * Submits a request to the default board.
	 *
	 * @return int Request ID.
	 */
	private function submit(): int {
		$result = SubmissionService::submit( $this->default_board(), $this->submission() );
		$this->assertIsArray( $result );

		return $result['id'];
	}
}
