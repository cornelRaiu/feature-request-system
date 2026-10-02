<?php
/**
 * Settings page tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use Akismet;
use FeatureRequestSystem\Admin\SettingsPage;
use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Settings;

/**
 * Feature Requests → Settings: what it shows, what it saves, and the note it puts on the form.
 */
final class SettingsPageTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once dirname( __DIR__ ) . '/Fakes/Akismet.php';
	}

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
		SettingsPage::register();
	}

	public function tear_down(): void {
		$_GET = [];
		Akismet::reset();
		unregister_setting( SettingsPage::GROUP, Settings::OPTION );
		parent::tear_down();
	}

	public function test_form_values_are_cleaned_before_they_are_saved(): void {
		$board = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );

		update_option(
			Settings::OPTION,
			[
				Settings::DEFAULT_BOARD            => (string) $board,
				Settings::DEFAULT_STATUS           => (string) $this->status( 'under-review' ),
				Settings::NOTIFY_NEW_REQUESTS      => '0',
				Settings::NOTIFY_EMAILS            => 'team@example.com, not an email; lead@example.com',
				Settings::DATA_USE_NOTE            => 'We <em>only</em> use it to <a href="https://example.org/contact" onclick="steal()">reply</a>.<script>alert(1)</script>',
				Settings::DELETE_DATA_ON_UNINSTALL => '1',
				Settings::SUBSCRIBE_VOTERS         => '0',
				'made_up'                          => 'ignored',
			]
		);

		$this->assertSame(
			[
				Settings::DEFAULT_BOARD            => $board,
				Settings::DEFAULT_STATUS           => $this->status( 'under-review' ),
				Settings::DELETE_DATA_ON_UNINSTALL => true,
				Settings::NOTIFY_NEW_REQUESTS      => false,
				Settings::NOTIFY_EMAILS            => 'team@example.com, lead@example.com',
				Settings::DATA_USE_NOTE            => 'We <em>only</em> use it to <a href="https://example.org/contact">reply</a>.alert(1)',
				Settings::NOTIFY_FOLLOWERS         => true,
				Settings::SUBSCRIBE_VOTERS         => false,
			],
			get_option( Settings::OPTION )
		);
		$this->assertStringContainsString( 'not, an, email', get_settings_errors( Settings::OPTION )[0]['message'], 'The user is told which addresses were left out.' );
	}

	public function test_boards_and_statuses_that_do_not_exist_cannot_become_the_default(): void {
		$board = $this->default_board();

		update_option(
			Settings::OPTION,
			[
				Settings::DEFAULT_BOARD  => '999999',
				Settings::DEFAULT_STATUS => (string) $board,
			]
		);

		$this->assertSame( $board, Settings::get( Settings::DEFAULT_BOARD ) );
		$this->assertSame( $this->status( 'open' ), Settings::get( Settings::DEFAULT_STATUS ), 'A board is not a status.' );
	}

	public function test_saving_one_setting_from_code_keeps_the_others(): void {
		$this->assertTrue( Settings::get( Settings::NOTIFY_NEW_REQUESTS ) );

		Settings::update( Settings::NOTIFY_EMAILS, 'team@example.com' );

		$this->assertSame( 'team@example.com', Settings::get( Settings::NOTIFY_EMAILS ) );
		$this->assertTrue( Settings::get( Settings::NOTIFY_NEW_REQUESTS ), 'Settings that were never saved keep their default.' );
		$this->assertSame( $this->default_board(), Settings::get( Settings::DEFAULT_BOARD ) );
	}

	public function test_page_shows_every_setting_with_its_current_value(): void {
		Settings::update( Settings::NOTIFY_EMAILS, 'team@example.com' );
		Settings::update( Settings::DATA_USE_NOTE, 'We only use it to reply.' );

		$html = $this->page();

		$this->assertStringContainsString( '<select id="mwtfrs-settings-default-board" name="' . Settings::OPTION . '[' . Settings::DEFAULT_BOARD . ']">', $html );
		$this->assertStringContainsString( '<option value="' . $this->default_board() . '" selected=\'selected\'>Feature Requests</option>', $html );
		$this->assertStringContainsString( '<option value="' . $this->status( 'open' ) . '" selected=\'selected\'>Open</option>', $html );
		$this->assertStringContainsString(
			'<input type="hidden" name="' . Settings::OPTION . '[' . Settings::NOTIFY_NEW_REQUESTS . ']" value="0"><label><input type="checkbox" name="' . Settings::OPTION . '[' . Settings::NOTIFY_NEW_REQUESTS . ']" value="1" checked=\'checked\'>',
			$html,
			'An unticked box still sends a value.'
		);
		$this->assertStringContainsString( 'value="team@example.com"', $html );
		$this->assertStringContainsString( '>We only use it to reply.</textarea>', $html );
		$this->assertStringContainsString( '[' . Settings::DELETE_DATA_ON_UNINSTALL . ']" value="1">', $html, 'Deleting data on uninstall starts unticked.' );

		foreach ( [ Settings::NOTIFY_FOLLOWERS, Settings::SUBSCRIBE_VOTERS ] as $follower_setting ) {
			$this->assertStringContainsString(
				'<input type="hidden" name="' . Settings::OPTION . '[' . $follower_setting . ']" value="0"><label><input type="checkbox" name="' . Settings::OPTION . '[' . $follower_setting . ']" value="1" checked=\'checked\'>',
				$html,
				'Following starts on: ' . $follower_setting
			);
		}
		$this->assertStringContainsString( 'The emails go out 5 minutes after the last change', $html );
	}

	public function test_suggested_note_mentions_akismet_only_when_it_is_connected(): void {
		$this->assertStringContainsString( 'A text to start from: We keep the name and email you enter', $this->page() );
		$this->assertStringNotContainsString( 'Akismet', $this->page() );

		Akismet::$api_key = 'key';
		$this->assertStringContainsString( 'your request is checked by Akismet', $this->page() );
	}

	public function test_page_says_when_settings_were_saved_or_what_was_wrong(): void {
		set_current_screen( 'edit-' . RequestPostType::NAME );
		add_settings_error( Settings::OPTION, Settings::NOTIFY_EMAILS, 'These are not valid email addresses and were left out: nope' );

		ob_start();
		SettingsPage::render();
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );

		$this->assertStringContainsString( 'These are not valid email addresses and were left out: nope', $html );
		$this->assertStringContainsString( '<form action="options.php" method="post">', $html );
	}

	public function test_page_is_under_feature_requests_for_those_who_manage_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		SettingsPage::add_page();

		$parent = 'edit.php?post_type=' . RequestPostType::NAME;
		$this->assertContains( SettingsPage::SLUG, wp_list_pluck( $GLOBALS['submenu'][ $parent ], 2 ) );
		$this->assertSame( Capabilities::MANAGE_SETTINGS, apply_filters( 'option_page_capability_' . SettingsPage::GROUP, 'manage_options' ) );

		unset( $GLOBALS['submenu'][ $parent ] );
	}

	public function test_guests_can_open_the_note_about_their_data_on_the_form(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
		$this->assertStringNotContainsString( '<details', $this->form(), 'No note, nothing to open.' );

		Settings::update( Settings::DATA_USE_NOTE, "We keep your <strong>email</strong> to reply.\n\nAsk us to remove it at any time." );
		$policy = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'wp_page_for_privacy_policy', $policy );

		$html = $this->form();

		$this->assertStringContainsString( '<summary>How we use your data</summary>', $html );
		$this->assertStringContainsString( '<p>We keep your <strong>email</strong> to reply.</p>', $html );
		$this->assertStringContainsString( '<p>Ask us to remove it at any time.</p>', $html );
		$this->assertStringContainsString( '<a href="' . get_permalink( $policy ) . '">Privacy policy</a>', $html );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringNotContainsString( '<details', $this->form(), 'Logged-in users are not asked for anything.' );
	}

	public function test_a_note_saved_some_other_way_is_cleaned_when_it_is_shown(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
		unregister_setting( SettingsPage::GROUP, Settings::OPTION );
		Settings::update( Settings::DATA_USE_NOTE, 'Read <a href="https://example.org/privacy">this</a>.<img src=x onerror=alert(1)>' );

		$html = $this->form();

		$this->assertStringContainsString( '<p>Read <a href="https://example.org/privacy">this</a>.</p>', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
	}

	/**
	 * Returns the fields of the settings page.
	 */
	private function page(): string {
		ob_start();
		do_settings_sections( SettingsPage::SLUG );

		return (string) ob_get_clean();
	}

	/**
	 * Renders the form block.
	 */
	private function form(): string {
		return render_block(
			[
				'blockName'    => SubmitForm::BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}
}
