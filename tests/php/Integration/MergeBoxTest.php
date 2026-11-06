<?php
/**
 * Merge box and merge settings tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\BoardFields;
use FeatureRequestSystem\Admin\MergeBox;
use FeatureRequestSystem\Admin\SettingsPage;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Merging\MergedStatus;
use FeatureRequestSystem\Merging\MergeService;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteRepository;
use RuntimeException;

/**
 * The "Merge into…" box on a request's edit screen, its form post, the notices after it,
 * and the "Email about a merge" settings of the site and of each board.
 */
final class MergeBoxTest extends TestCase {

	/**
	 * The duplicate on the edit screen.
	 *
	 * @var int
	 */
	private $duplicate = 0;

	/**
	 * The request it is like.
	 *
	 * @var int
	 */
	private $target = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	public function set_up(): void {
		parent::set_up();
		$_POST = [];
		$_GET  = [];

		$this->duplicate = $this->create_request( [ 'post_title' => 'Dark theme for the dashboard' ] );
		$this->target    = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		VoteRepository::add( $this->duplicate, hash( 'sha256', 'g:one' ), 0 );
		VoteRepository::add( $this->target, hash( 'sha256', 'g:two' ), 0 );
		VoteCount::sync( $this->duplicate );
		VoteCount::sync( $this->target );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	public function tear_down(): void {
		global $wp_meta_boxes;

		$_POST         = [];
		$_GET          = [];
		$wp_meta_boxes = [];
		unregister_setting( SettingsPage::GROUP, Settings::OPTION );
		parent::tear_down();
	}

	public function test_moderators_get_the_box_on_requests_that_exist(): void {
		$this->assertTrue( $this->has_box( $this->duplicate ) );
		$this->assertTrue( $this->has_box( $this->create_request( [ 'post_status' => 'pending' ] ) ), 'A duplicate waiting for approval can be merged too.' );
		$this->assertFalse( $this->has_box( $this->create_request( [ 'post_status' => 'auto-draft' ] ) ), 'Not on a request that is still being written.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertFalse( $this->has_box( $this->duplicate ) );
	}

	public function test_the_box_offers_the_requests_with_similar_titles_and_asks_to_confirm(): void {
		$other_board = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		wp_set_object_terms( $this->create_request( [ 'post_title' => 'Dark colours' ] ), $other_board, BoardTaxonomy::NAME );
		$this->create_request( [ 'post_title' => 'Export to CSV' ] );

		$html = $this->box( $this->duplicate );

		$this->assertStringContainsString( '<input type="radio" name="' . MergeBox::FIELD_TARGET . '" value="' . $this->target . '"> Dark mode <span class="description">(1 vote · Open)</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'type="radio"' ), 'Not itself, not another board, not a title without a word in common.' );
		$this->assertStringContainsString( 'Merging cannot be undone. I want to merge this request.', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="' . MergeBox::FIELD_CONFIRM . '" value="1">', $html, 'Unticked.' );
		$this->assertStringContainsString( '<input type="hidden" name="' . MergeBox::FIELD_REQUEST . '" value="' . $this->duplicate . '">', $html );
		$this->assertStringContainsString( 'name="' . MergeBox::NONCE_FIELD . '" value="' . wp_create_nonce( MergeBox::ACTION . '-' . $this->duplicate ) . '"', $html );
		$this->assertStringContainsString( '<button type="submit" class="button" name="action" value="' . MergeBox::ACTION . '" formaction="' . admin_url( 'admin-post.php' ) . '">Merge</button>', $html );
		$this->assertStringContainsString( '<label for="mwtfrs-merge-search">Find another request by its title</label>', $html );
		$this->assertStringContainsString( 'exclude=' . $this->duplicate, $html, 'The search leaves the request itself out.' );
		$this->assertStringContainsString( BoardTaxonomy::NAME . '=' . $this->default_board(), $html, 'The search keeps to the board.' );
	}

	public function test_a_merged_request_says_where_it_went(): void {
		MergeService::merge( $this->duplicate, $this->target );

		$html = $this->box( $this->duplicate );

		$this->assertStringContainsString( 'This request was merged into <a href="' . esc_url( get_edit_post_link( $this->target ) ) . '">Dark mode</a>. Its address leads there.', $html );
		$this->assertStringNotContainsString( 'type="radio"', $html );
		$this->assertStringNotContainsString( MergeBox::ACTION, $html );
		$this->assertStringContainsString( '<a class="submitdelete" href="' . esc_url( (string) get_delete_post_link( $this->duplicate ) ) . '">Move to Trash</a>', $html );
	}

	public function test_a_merged_request_has_no_publish_box_that_would_change_nothing(): void {
		global $wp_meta_boxes;

		MergeService::merge( $this->duplicate, $this->target );
		$wp_meta_boxes = [];
		add_meta_box( 'submitdiv', 'Publish', '__return_empty_string', RequestPostType::NAME, 'side', 'core' );

		MergeBox::add( get_post( $this->duplicate ) );

		$this->assertFalse( $wp_meta_boxes[ RequestPostType::NAME ]['side']['core']['submitdiv'], 'Removed.' );
		$this->assertSame( 'Merged', $wp_meta_boxes[ RequestPostType::NAME ]['side']['high']['mwtfrs-merge']['title'], 'This box in its place.' );

		$wp_meta_boxes = [];
		add_meta_box( 'submitdiv', 'Publish', '__return_empty_string', RequestPostType::NAME, 'side', 'core' );
		MergeBox::add( get_post( $this->target ) );
		$this->assertIsArray( $wp_meta_boxes[ RequestPostType::NAME ]['side']['core']['submitdiv'], 'Other requests keep theirs.' );
	}

	public function test_the_form_merges_and_leads_to_the_request_the_votes_went_to(): void {
		$location = $this->post_form( $this->form() );

		$this->assertSame( add_query_arg( MergeBox::ARG_MERGED, $this->duplicate, admin_url( 'post.php?post=' . $this->target . '&action=edit' ) ), $location );
		$this->assertSame( MergedStatus::NAME, get_post_status( $this->duplicate ) );
		$this->assertSame( 2, VoteCount::get( $this->target ) );

		$_GET[ MergeBox::ARG_MERGED ] = (string) $this->duplicate;
		$this->assertStringContainsString( '&quot;Dark theme for the dashboard&quot; was merged into this request. Its votes and followers are here now', $this->notice() );
	}

	public function test_nothing_is_merged_without_the_box_ticked(): void {
		$location = $this->post_form( $this->form( [ MergeBox::FIELD_CONFIRM => '' ] ) );

		$this->assertSame( $this->back( 'confirm' ), $location );
		$this->assertSame( 'publish', get_post_status( $this->duplicate ) );
		$this->assertSame( 1, VoteCount::get( $this->target ) );

		$_GET[ MergeBox::ARG_ERROR ] = 'confirm';
		$this->assertStringContainsString( 'Nothing was merged. Tick the box to confirm, because merging cannot be undone.', $this->notice() );
	}

	public function test_the_form_needs_a_request_to_merge_into_and_a_valid_nonce(): void {
		$this->assertSame( $this->back( 'target' ), MergeBox::process( $this->form( [ MergeBox::FIELD_TARGET => '' ] ) ) );
		$this->assertSame( $this->back( 'expired' ), MergeBox::process( $this->form( [ MergeBox::NONCE_FIELD => 'stale' ] ) ) );
		$this->assertSame( 'publish', get_post_status( $this->duplicate ) );
	}

	public function test_only_moderators_can_merge_with_the_form(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertSame( $this->back( 'denied' ), MergeBox::process( $this->form() ) );
		$this->assertSame( 'publish', get_post_status( $this->duplicate ) );
	}

	public function test_a_refused_merge_comes_back_with_the_reason(): void {
		$other_board = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		wp_set_object_terms( $this->target, $other_board, BoardTaxonomy::NAME );

		$this->assertSame( $this->back( MergeService::ERROR_OTHER_BOARD ), MergeBox::process( $this->form() ) );

		$_GET[ MergeBox::ARG_ERROR ] = MergeService::ERROR_OTHER_BOARD;
		$this->assertStringContainsString( 'Requests can only be merged into a request on the same board.', $this->notice() );

		$_GET[ MergeBox::ARG_ERROR ] = '<script>made-up</script>';
		$this->assertSame( '', $this->notice(), 'A code it does not know shows nothing.' );
	}

	public function test_the_site_setting_starts_at_everyone_who_followed_and_keeps_only_valid_choices(): void {
		SettingsPage::register();

		$this->assertSame( Settings::MERGE_EMAIL_FOLLOWERS, Settings::get( Settings::MERGE_EMAIL ) );
		$html = $this->settings_page();
		$this->assertStringContainsString( '<label for="mwtfrs-settings-merge-email">Email about a merge</label>', $html );
		$this->assertStringContainsString( '<select id="mwtfrs-settings-merge-email" name="' . Settings::OPTION . '[' . Settings::MERGE_EMAIL . ']">', $html );
		$this->assertStringContainsString( '<option value="followers" selected=\'selected\'>Everyone who followed the merged request</option>', $html );
		$this->assertStringContainsString( '<option value="submitter">Only the person who suggested it</option>', $html );
		$this->assertStringContainsString( '<option value="nobody">Nobody</option>', $html );

		update_option( Settings::OPTION, [ Settings::MERGE_EMAIL => Settings::MERGE_EMAIL_SUBMITTER ] );
		$this->assertSame( Settings::MERGE_EMAIL_SUBMITTER, Settings::get( Settings::MERGE_EMAIL ) );

		update_option( Settings::OPTION, [ Settings::MERGE_EMAIL => 'everyone-on-the-site' ] );
		$this->assertSame( Settings::MERGE_EMAIL_SUBMITTER, Settings::get( Settings::MERGE_EMAIL ), 'A value that is not a choice keeps the current one.' );
	}

	public function test_a_board_keeps_the_sites_choice_until_it_picks_its_own(): void {
		Settings::update( Settings::MERGE_EMAIL, Settings::MERGE_EMAIL_NOBODY );
		$board = $this->default_board();

		$html = $this->board_form( $board );
		$this->assertStringContainsString( '<label for="mwtfrs-board-merge">Email about a merge</label>', $html );
		$this->assertStringContainsString( '<option value="site" selected=\'selected\'>The site&#039;s choice (Nobody)</option>', $html );
		$this->assertSame( Settings::MERGE_EMAIL_NOBODY, BoardSettings::merge_email( $board ) );

		$this->save_board( $board, Settings::MERGE_EMAIL_SUBMITTER );
		$this->assertSame( Settings::MERGE_EMAIL_SUBMITTER, BoardSettings::get( $board )[ BoardSettings::MERGE_EMAIL ] );
		$this->assertSame( Settings::MERGE_EMAIL_SUBMITTER, BoardSettings::merge_email( $board ) );
		$this->assertStringContainsString( '<option value="submitter" selected=\'selected\'>Only the person who suggested it</option>', $this->board_form( $board ) );

		$this->save_board( $board, 'made-up' );
		$this->assertSame( Settings::MERGE_EMAIL_SUBMITTER, BoardSettings::get( $board )[ BoardSettings::MERGE_EMAIL ], 'A value that is not a choice keeps the current one.' );
		BoardSettings::update( $board, [ BoardSettings::MERGE_EMAIL => [ Settings::MERGE_EMAIL_NOBODY ] ] );
		$this->assertSame( Settings::MERGE_EMAIL_SUBMITTER, BoardSettings::get( $board )[ BoardSettings::MERGE_EMAIL ], 'Nor does a list.' );

		$this->save_board( $board, BoardSettings::USE_SITE );
		$this->assertSame( Settings::MERGE_EMAIL_NOBODY, BoardSettings::merge_email( $board ) );
	}

	/**
	 * Fields of the box's form, as the edit screen sends them with the box filled in.
	 *
	 * @param array<string, string> $fields Fields to override.
	 * @return array<string, string>
	 */
	private function form( array $fields = [] ): array {
		return array_merge(
			[
				// The edit screen's own fields come along. They are not the box's business.
				'post_ID'               => (string) $this->duplicate,
				'post_title'            => 'Changed but not saved',
				'action'                => MergeBox::ACTION,
				MergeBox::FIELD_REQUEST => (string) $this->duplicate,
				MergeBox::FIELD_TARGET  => (string) $this->target,
				MergeBox::FIELD_CONFIRM => '1',
				MergeBox::NONCE_FIELD   => wp_create_nonce( MergeBox::ACTION . '-' . $this->duplicate ),
			],
			$fields
		);
	}

	/**
	 * Posts the form to admin-post.php and returns where it redirects to.
	 *
	 * @param array<string, string> $fields Posted fields.
	 */
	private function post_form( array $fields ): string {
		$_POST = wp_slash( $fields );
		add_filter(
			'wp_redirect',
			static function ( string $location ) {
				throw new RuntimeException( $location );
			}
		);

		try {
			do_action( 'admin_post_' . MergeBox::ACTION );
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	/**
	 * The edit screen of the duplicate, with a refusal code.
	 *
	 * @param string $code Refusal code.
	 */
	private function back( string $code ): string {
		return add_query_arg( MergeBox::ARG_ERROR, $code, admin_url( 'post.php?post=' . $this->duplicate . '&action=edit' ) );
	}

	/**
	 * Whether the edit screen of a request gets the box.
	 *
	 * @param int $request_id Request ID.
	 */
	private function has_box( int $request_id ): bool {
		global $wp_meta_boxes;

		$wp_meta_boxes = [];
		MergeBox::add( get_post( $request_id ) );

		return ! empty( $wp_meta_boxes[ RequestPostType::NAME ]['side']['low']['mwtfrs-merge'] );
	}

	/**
	 * Renders the box for a request.
	 *
	 * @param int $request_id Request ID.
	 */
	private function box( int $request_id ): string {
		ob_start();
		MergeBox::render( get_post( $request_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Renders the notices of a request's edit screen.
	 */
	private function notice(): string {
		set_current_screen( RequestPostType::NAME );
		ob_start();
		MergeBox::render_notice();
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );

		return $html;
	}

	/**
	 * Renders the settings page's sections.
	 */
	private function settings_page(): string {
		ob_start();
		do_settings_sections( SettingsPage::SLUG );

		return (string) ob_get_clean();
	}

	/**
	 * Renders the form that edits a board.
	 *
	 * @param int $board_id Board term ID.
	 */
	private function board_form( int $board_id ): string {
		ob_start();
		do_action( BoardTaxonomy::NAME . '_edit_form_fields', get_term( $board_id ), BoardTaxonomy::NAME );

		return (string) ob_get_clean();
	}

	/**
	 * Saves a board's form with a choice for the merge email and its other settings as they are.
	 *
	 * @param int    $board_id Board term ID.
	 * @param string $choice   Value of the field.
	 */
	private function save_board( int $board_id, string $choice ): void {
		$settings = BoardSettings::get( $board_id );
		$_POST    = [
			BoardFields::NONCE_FIELD => wp_create_nonce( BoardFields::NONCE_ACTION ),
			BoardFields::FIELD       => [
				BoardSettings::VOTING      => $settings[ BoardSettings::VOTING ],
				BoardSettings::SUBMITTING  => $settings[ BoardSettings::SUBMITTING ],
				BoardSettings::MODERATION  => '1',
				BoardSettings::MERGE_EMAIL => $choice,
			],
		];
		do_action( 'edited_' . BoardTaxonomy::NAME, $board_id, 0 );
	}
}
