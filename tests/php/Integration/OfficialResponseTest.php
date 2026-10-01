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

	public function test_moderators_get_a_link_on_the_reply_line_of_team_comments(): void {
		$by_team    = get_comment( $this->comment_by( 'editor' ) );
		$by_visitor = self::factory()->comment->create_and_get(
			[
				'comment_post_ID'  => $by_team->comment_post_ID,
				'comment_approved' => '1',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to( get_permalink( (int) $by_team->comment_post_ID ) );

		$line = $this->reply_line( $by_team );
		$this->assertMatchesRegularExpression(
			// WordPress quotes the Reply link's attributes differently from version to version.
			'#^<div class="reply"><a [^>]*class=["\']comment-reply-link["\'][^>]*>Reply</a> <span class="mwtfrs-official-toggle"><a href="[^"]+">Mark as official response</a></span></div>$#',
			$line,
			'The link sits inside what the theme wraps around Reply, so both are on one line.'
		);
		$this->assertStringContainsString( 'official=1', $line );
		$this->assertStringContainsString( rawurlencode( get_comment_link( $by_team ) ), $line, 'The link returns to this comment.' );
		$this->assertStringEndsWith( 'Mark as official response</a></span>', get_comment_reply_link( $this->reply_args(), $by_team ), 'The Comments block adds no wrapper of its own here.' );
		$this->assertStringNotContainsString( OfficialResponseLink::ACTION, $this->comment_text( (int) $by_team->comment_ID ), 'Not below the text as well.' );

		$this->assertStringNotContainsString( OfficialResponseLink::ACTION, $this->reply_line( $by_visitor ), 'A visitor\'s comment cannot be marked.' );

		OfficialResponse::mark( (int) $by_team->comment_ID, true );
		$line = $this->reply_line( $by_team );
		$this->assertStringContainsString( '>Remove official mark</a>', $line );
		$this->assertStringContainsString( 'official=0', $line );
	}

	public function test_comments_without_a_reply_link_get_the_link_below_their_text(): void {
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$request = $this->create_request();
		$top     = self::factory()->comment->create_and_get(
			[
				'comment_post_ID'  => $request,
				'user_id'          => $editor,
				'comment_approved' => '1',
			]
		);
		$nested  = self::factory()->comment->create_and_get(
			[
				'comment_post_ID'  => $request,
				'comment_parent'   => $top->comment_ID,
				'user_id'          => $editor,
				'comment_approved' => '1',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to( get_permalink( $request ) );
		$below_text = '<p class="mwtfrs-official-action"><a href=';

		update_option( 'thread_comments_depth', 2 );
		$this->assertStringContainsString( $below_text, $this->comment_text( (int) $nested->comment_ID ), 'A comment at the deepest level.' );
		$this->assertStringNotContainsString( $below_text, $this->comment_text( (int) $top->comment_ID ), 'A comment above it still has its Reply line.' );
		$this->assertStringNotContainsString(
			OfficialResponseLink::ACTION,
			(string) get_comment_reply_link( $this->reply_args( 5 ), $nested ),
			'Never in both places, even when a theme nests deeper than the site\'s setting.'
		);

		update_option( 'thread_comments', 0 );
		$this->assertStringContainsString( $below_text, $this->comment_text( (int) $top->comment_ID ), 'Threaded comments are turned off.' );

		update_option( 'thread_comments', 1 );
		wp_update_post(
			[
				'ID'             => $request,
				'comment_status' => 'closed',
			]
		);
		$this->assertStringContainsString( $below_text, $this->comment_text( (int) $top->comment_ID ), 'The request no longer takes comments.' );
	}

	public function test_the_links_are_for_moderators_on_the_request_page_only(): void {
		$comment = get_comment( $this->comment_by( 'editor' ) );
		update_comment_meta( (int) $comment->comment_ID, OfficialResponse::META, 1 );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'subscriber' ] ) ] as $visitor ) {
			wp_set_current_user( $visitor );
			$this->go_to( get_permalink( (int) $comment->comment_post_ID ) );
			$this->assertStringNotContainsString( OfficialResponseLink::ACTION, $this->reply_line( $comment ) . $this->comment_text( (int) $comment->comment_ID ) );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to( home_url( '/' ) );
		$this->assertStringNotContainsString(
			OfficialResponseLink::ACTION,
			$this->reply_line( $comment ) . $this->comment_text( (int) $comment->comment_ID ),
			'Not where comments are shown elsewhere, such as the Comments screen or the REST API.'
		);
	}

	public function test_the_comment_edit_screen_offers_a_checkbox_for_the_mark(): void {
		$by_team    = get_comment( $this->comment_by( 'editor' ) );
		$by_visitor = get_comment( $this->comment_by( 'subscriber' ) );
		$unticked   = '<input type="checkbox" name="' . CommentForm::FIELD . '" value="1"> Official response';
		$ticked     = '<input type="checkbox" name="' . CommentForm::FIELD . '" value="1" checked=\'checked\'> Official response';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertStringContainsString( $unticked, $this->save_box( $by_team ) );
		$this->assertSame( '', $this->save_box( $by_visitor ), 'A visitor\'s comment cannot be marked.' );

		OfficialResponse::mark( (int) $by_team->comment_ID, true );
		$this->assertStringContainsString( $ticked, $this->save_box( $by_team ) );

		get_userdata( (int) $by_team->user_id )->set_role( 'subscriber' );
		$this->assertStringContainsString( $ticked, $this->save_box( $by_team ), 'The mark can still be removed after its author left the team.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( '', $this->save_box( $by_team ) );
	}

	public function test_saving_the_comment_edit_screen_sets_and_clears_the_mark(): void {
		$comment = $this->comment_by( 'editor' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->save_edit_screen( $comment, [ CommentForm::FIELD => '1' ] );
		$this->assertTrue( OfficialResponse::is_official( $comment ) );

		$this->save_edit_screen( $comment, [ CommentForm::FIELD => '0' ] );
		$this->assertFalse( OfficialResponse::is_official( $comment ) );
	}

	public function test_other_ways_of_updating_a_comment_leave_the_mark_alone(): void {
		$comment = $this->comment_by( 'editor' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		OfficialResponse::mark( $comment, true );

		$this->save_edit_screen( $comment, [] );
		$this->assertTrue( OfficialResponse::is_official( $comment ), 'A form without the checkbox, as Quick Edit sends.' );

		$this->save_edit_screen( $comment, [ CommentForm::FIELD => '0' ], 'forged' );
		$this->assertTrue( OfficialResponse::is_official( $comment ), 'A form without the edit screen\'s nonce.' );

		$_POST = [];
		wp_update_comment(
			[
				'comment_ID'      => $comment,
				'comment_content' => 'Edited by other code.',
			]
		);
		$this->assertTrue( OfficialResponse::is_official( $comment ), 'An update from code or the REST API.' );
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
	 * Returns a comment's Reply link the way a classic theme's comment list prints it.
	 *
	 * @param \WP_Comment $comment Comment.
	 */
	private function reply_line( \WP_Comment $comment ): string {
		return (string) get_comment_reply_link(
			array_merge(
				$this->reply_args(),
				[
					'before' => '<div class="reply">',
					'after'  => '</div>',
				]
			),
			$comment
		);
	}

	/**
	 * Arguments WordPress passes for the Reply link of a top-level comment.
	 *
	 * @param int $max_depth Deepest level the list nests to. Defaults to the site's setting.
	 * @return array<string, int>
	 */
	private function reply_args( int $max_depth = 0 ): array {
		return [
			'depth'     => 1,
			'max_depth' => $max_depth ? $max_depth : (int) get_option( 'thread_comments_depth' ),
		];
	}

	/**
	 * Returns what the plugin adds to the Save box on a comment's edit screen.
	 *
	 * @param \WP_Comment $comment Comment being edited.
	 */
	private function save_box( \WP_Comment $comment ): string {
		return (string) apply_filters( 'edit_comment_misc_actions', '', $comment );
	}

	/**
	 * Saves a comment's edit screen, the way wp-admin/comment.php does after checking the nonce.
	 *
	 * @param int                   $comment_id Comment ID.
	 * @param array<string, string> $fields     Extra fields the form sends.
	 * @param string                $nonce      Nonce the form sends. Defaults to a valid one.
	 */
	private function save_edit_screen( int $comment_id, array $fields, string $nonce = '' ): void {
		require_once ABSPATH . 'wp-admin/includes/comment.php';

		$_POST = array_merge(
			[
				'comment_ID'     => (string) $comment_id,
				'content'        => 'Planned for 2.4.',
				'comment_status' => '1',
				'_wpnonce'       => '' !== $nonce ? $nonce : wp_create_nonce( 'update-comment_' . $comment_id ),
			],
			$fields
		);

		edit_comment();
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
