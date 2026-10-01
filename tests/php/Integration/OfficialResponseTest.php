<?php
/**
 * Official response tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Comments\CommentForm;
use FeatureRequestSystem\Comments\OfficialResponse;
use FeatureRequestSystem\Comments\OfficialResponseLink;
use RuntimeException;
use WPDieException;

/**
 * Moderators mark their team's comments on requests as official responses.
 */
final class OfficialResponseTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$_POST    = [];
		$_GET     = [];
		$_REQUEST = [];
	}

	public function tear_down(): void {
		$_POST    = [];
		$_GET     = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function test_moderators_mark_and_unmark_a_team_members_comment(): void {
		$comment = $this->comment_by( 'editor' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertFalse( OfficialResponse::is_official( $comment ) );

		$this->assertTrue( OfficialResponse::mark( $comment, true ) );
		$this->assertTrue( OfficialResponse::is_official( $comment ) );

		$this->assertTrue( OfficialResponse::mark( $comment, false ) );
		$this->assertFalse( OfficialResponse::is_official( $comment ) );
	}

	public function test_only_moderators_can_mark_comments(): void {
		$comment = $this->comment_by( 'editor' );

		foreach ( [ 'subscriber', 'author' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertFalse( OfficialResponse::mark( $comment, true ), $role );
		}
		wp_set_current_user( 0 );
		$this->assertFalse( OfficialResponse::mark( $comment, true ), 'guest' );

		$this->assertFalse( OfficialResponse::is_official( $comment ) );
	}

	public function test_only_comments_written_by_moderators_can_be_official(): void {
		$by_subscriber = $this->comment_by( 'subscriber' );
		$by_guest      = self::factory()->comment->create( [ 'comment_post_ID' => $this->create_request() ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertFalse( OfficialResponse::mark( $by_subscriber, true ) );
		$this->assertFalse( OfficialResponse::mark( $by_guest, true ) );
		$this->assertFalse( OfficialResponse::mark( 999999, true ), 'A comment that does not exist.' );
	}

	public function test_the_mark_can_be_removed_after_its_author_left_the_team(): void {
		$comment = get_comment( $this->comment_by( 'editor' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		OfficialResponse::mark( (int) $comment->comment_ID, true );

		get_userdata( (int) $comment->user_id )->set_role( 'subscriber' );

		$this->assertStringContainsString( 'Remove official mark', apply_filters( 'comment_row_actions', [], $comment )[ OfficialResponseLink::ACTION ] );
		$this->assertTrue( OfficialResponse::mark( (int) $comment->comment_ID, false ) );
		$this->assertFalse( OfficialResponse::is_official( (int) $comment->comment_ID ) );
		$this->assertFalse( OfficialResponse::mark( (int) $comment->comment_ID, true ), 'It cannot be marked again.' );
	}

	public function test_only_comments_on_requests_can_be_official(): void {
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$comment = self::factory()->comment->create(
			[
				'comment_post_ID' => self::factory()->post->create(),
				'user_id'         => $editor,
			]
		);
		wp_set_current_user( $editor );

		$this->assertFalse( OfficialResponse::mark( $comment, true ) );
	}

	public function test_comment_form_offers_the_option_to_moderators_on_requests_only(): void {
		$request = $this->create_request();
		$post    = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$form = $this->comment_form( $request );
		$this->assertStringContainsString( '<input type="checkbox" name="' . CommentForm::FIELD . '" value="1"> Post as the official response', $form );
		$this->assertStringContainsString( 'name="' . CommentForm::NONCE_FIELD . '" value="' . wp_create_nonce( CommentForm::NONCE_ACTION ) . '"', $form );
		$this->assertLessThan( strpos( $form, 'name="submit"' ), strpos( $form, CommentForm::FIELD ), 'The option sits above the submit button.' );
		$this->assertStringNotContainsString( CommentForm::FIELD, $this->comment_form( $post ), 'Not on other post types.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertStringNotContainsString( CommentForm::FIELD, $this->comment_form( $request ), 'Not for other users.' );
	}

	public function test_ticking_the_option_makes_the_new_comment_official(): void {
		$request = $this->create_request();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$_POST = [
			CommentForm::FIELD       => '1',
			CommentForm::NONCE_FIELD => wp_create_nonce( CommentForm::NONCE_ACTION ),
		];

		$comment = $this->post_comment( $request, 'Planned for 2.4.' );

		$this->assertTrue( OfficialResponse::is_official( $comment ) );
		$this->assertSame( $comment, (int) OfficialResponse::latest( $request )->comment_ID );
	}

	public function test_the_option_needs_its_nonce_and_a_moderator(): void {
		$request = $this->create_request();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$_POST = [ CommentForm::FIELD => '1' ];
		$this->assertFalse( OfficialResponse::is_official( $this->post_comment( $request, 'Sent by a form on another site.' ) ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST = [
			CommentForm::FIELD       => '1',
			CommentForm::NONCE_FIELD => wp_create_nonce( CommentForm::NONCE_ACTION ),
		];
		$this->assertFalse( OfficialResponse::is_official( $this->post_comment( $request, 'A visitor adds the field by hand.' ) ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$_POST = [];
		$this->assertFalse( OfficialResponse::is_official( $this->post_comment( $request, 'An ordinary reply from the team.' ) ) );
	}

	public function test_official_responses_are_labelled_in_the_comment_list(): void {
		$official = $this->comment_by( 'editor' );
		$ordinary = $this->comment_by( 'editor' );
		update_comment_meta( $official, OfficialResponse::META, 1 );

		$this->assertStringContainsString( '<strong>Official response</strong>', $this->comment_text( $official ) );
		$this->assertContains( 'mwtfrs-official', get_comment_class( '', $official ) );

		$this->assertStringNotContainsString( 'Official response', $this->comment_text( $ordinary ) );
		$this->assertNotContains( 'mwtfrs-official', get_comment_class( '', $ordinary ) );
	}

	public function test_comments_screen_offers_the_action_on_team_comments(): void {
		$by_team    = get_comment( $this->comment_by( 'editor' ) );
		$by_visitor = get_comment( $this->comment_by( 'subscriber' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$actions = apply_filters( 'comment_row_actions', [], $by_team );
		$this->assertStringContainsString( '>Mark as official response</a>', $actions[ OfficialResponseLink::ACTION ] );
		$this->assertStringContainsString( 'comment=' . $by_team->comment_ID, $actions[ OfficialResponseLink::ACTION ] );
		$this->assertStringContainsString( 'official=1', $actions[ OfficialResponseLink::ACTION ] );
		$this->assertStringContainsString( '_wpnonce=', $actions[ OfficialResponseLink::ACTION ] );

		OfficialResponse::mark( (int) $by_team->comment_ID, true );
		$actions = apply_filters( 'comment_row_actions', [], $by_team );
		$this->assertStringContainsString( '>Remove official mark</a>', $actions[ OfficialResponseLink::ACTION ] );
		$this->assertStringContainsString( 'official=0', $actions[ OfficialResponseLink::ACTION ] );

		$this->assertArrayNotHasKey( OfficialResponseLink::ACTION, apply_filters( 'comment_row_actions', [], $by_visitor ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertArrayNotHasKey( OfficialResponseLink::ACTION, apply_filters( 'comment_row_actions', [], $by_team ) );
	}

	public function test_moderators_get_a_link_under_team_comments_on_the_request_page(): void {
		$by_team    = get_comment( $this->comment_by( 'editor' ) );
		$by_visitor = self::factory()->comment->create_and_get(
			[
				'comment_post_ID'  => $by_team->comment_post_ID,
				'comment_approved' => '1',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to( get_permalink( (int) $by_team->comment_post_ID ) );

		$text = $this->comment_text( (int) $by_team->comment_ID );
		$this->assertStringContainsString( '>Mark as official response</a>', $text );
		$this->assertStringContainsString( 'official=1', $text );
		$this->assertStringContainsString( rawurlencode( get_comment_link( $by_team ) ), $text, 'The link returns to this comment.' );
		$this->assertStringNotContainsString( 'official response', $this->comment_text( (int) $by_visitor->comment_ID ), 'A visitor\'s comment cannot be marked.' );

		OfficialResponse::mark( (int) $by_team->comment_ID, true );
		$text = $this->comment_text( (int) $by_team->comment_ID );
		$this->assertStringContainsString( '>Remove official mark</a>', $text );
		$this->assertStringContainsString( 'official=0', $text );
	}

	public function test_the_links_are_for_moderators_on_the_request_page_only(): void {
		$comment = get_comment( $this->comment_by( 'editor' ) );
		update_comment_meta( (int) $comment->comment_ID, OfficialResponse::META, 1 );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'subscriber' ] ) ] as $visitor ) {
			wp_set_current_user( $visitor );
			$this->go_to( get_permalink( (int) $comment->comment_post_ID ) );
			$this->assertStringNotContainsString( OfficialResponseLink::ACTION, $this->comment_text( (int) $comment->comment_ID ) );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to( home_url( '/' ) );
		$this->assertStringNotContainsString( OfficialResponseLink::ACTION, $this->comment_text( (int) $comment->comment_ID ), 'Not where comments are shown elsewhere, such as the Comments screen or the REST API.' );
	}

	public function test_a_link_on_the_request_page_returns_there(): void {
		$comment = get_comment( $this->comment_by( 'editor' ) );
		$back    = get_comment_link( $comment );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->follow_action_link( (int) $comment->comment_ID, wp_create_nonce( OfficialResponseLink::ACTION . '_' . $comment->comment_ID ), $back );

		$this->assertSame( $back, $this->run_action() );
		$this->assertTrue( OfficialResponse::is_official( (int) $comment->comment_ID ) );
	}

	public function test_the_action_marks_the_comment_and_returns_to_the_comments_screen(): void {
		$comment = $this->comment_by( 'editor' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->follow_action_link( $comment, wp_create_nonce( OfficialResponseLink::ACTION . '_' . $comment ) );

		$this->assertStringContainsString( 'edit-comments.php', $this->run_action() );
		$this->assertTrue( OfficialResponse::is_official( $comment ) );
	}

	public function test_the_action_refuses_a_forged_link(): void {
		$comment = $this->comment_by( 'editor' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->follow_action_link( $comment, 'forged' );

		try {
			$this->run_action();
			$this->fail( 'A link without a valid nonce was accepted.' );
		} catch ( WPDieException $refused ) {
			$this->assertFalse( OfficialResponse::is_official( $comment ) );
		}
	}

	/**
	 * Creates an approved comment on a new request, written by a user with the given role.
	 *
	 * @param string $role Role of the comment's author.
	 */
	private function comment_by( string $role ): int {
		$user = self::factory()->user->create( [ 'role' => $role ] );

		return self::factory()->comment->create(
			[
				'comment_post_ID'  => $this->create_request(),
				'user_id'          => $user,
				'comment_approved' => '1',
			]
		);
	}

	/**
	 * Renders the comment form on a post's page.
	 *
	 * @param int $post_id Post ID.
	 */
	private function comment_form( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		ob_start();
		comment_form();

		return (string) ob_get_clean();
	}

	/**
	 * Posts a comment as the current user, the way wp-comments-post.php does.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $text    Comment text.
	 * @return int Comment ID.
	 */
	private function post_comment( int $post_id, string $text ): int {
		$comment = wp_handle_comment_submission(
			[
				'comment_post_ID' => $post_id,
				'comment'         => $text,
			]
		);

		$this->assertInstanceOf( \WP_Comment::class, $comment );

		return (int) $comment->comment_ID;
	}

	/**
	 * Returns a comment's text as the comment list shows it.
	 *
	 * @param int $comment_id Comment ID.
	 */
	private function comment_text( int $comment_id ): string {
		$comment = get_comment( $comment_id );

		return (string) apply_filters( 'comment_text', get_comment_text( $comment ), $comment, [] );
	}

	/**
	 * Sets up the request a click on a link sends.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $nonce      Nonce in the link.
	 * @param string $back       Page the link says to return to, if any.
	 */
	private function follow_action_link( int $comment_id, string $nonce, string $back = '' ): void {
		$_GET     = array_filter(
			[
				'action'           => OfficialResponseLink::ACTION,
				'comment'          => (string) $comment_id,
				'official'         => '1',
				'_wpnonce'         => $nonce,
				'_wp_http_referer' => $back,
			]
		);
		$_REQUEST = $_GET;
	}

	/**
	 * Runs the action handler and returns where it redirects to.
	 */
	private function run_action(): string {
		add_filter(
			'wp_redirect',
			static function ( string $location ) {
				throw new RuntimeException( $location );
			}
		);

		try {
			OfficialResponseLink::handle();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}
}
