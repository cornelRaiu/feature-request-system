<?php
/**
 * Merge email tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Merging\MergeService;
use FeatureRequestSystem\Notifications\MergeNotifier;
use FeatureRequestSystem\Notifications\Queue;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Unsubscribe;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Voting\VoteRepository;

/**
 * Who hears about a merge, as the site and each board chose: everyone who followed the
 * merged request, only the person who suggested it, or nobody. Never the person who
 * merged, and nobody twice.
 */
final class MergeNotifierTest extends TestCase {

	/**
	 * The moderator who merges.
	 *
	 * @var int
	 */
	private $moderator = 0;

	/**
	 * The merged request.
	 *
	 * @var int
	 */
	private $duplicate = 0;

	/**
	 * The request it is merged into.
	 *
	 * @var int
	 */
	private $target = 0;

	/**
	 * The user who suggested the duplicate, and follows it.
	 *
	 * @var int
	 */
	private $submitter = 0;

	/**
	 * Two users who follow the duplicate.
	 *
	 * @var int[]
	 */
	private $followers = [];

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();

		$this->moderator = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'moderator@example.com',
			]
		);
		wp_set_current_user( $this->moderator );

		$this->duplicate = $this->create_request( [ 'post_title' => 'Night theme' ] );
		$this->target    = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->submitter = $this->user( 'sam' );
		update_post_meta( $this->duplicate, RequestPostType::META_SUBMITTER_ID, $this->submitter );
		SubscriptionRepository::add( $this->duplicate, $this->submitter, SubscriptionRepository::SOURCE_AUTHOR );
		$this->followers = [ $this->user( 'ana' ), $this->user( 'ben' ) ];
		foreach ( $this->followers as $follower ) {
			SubscriptionRepository::add( $this->duplicate, $follower, SubscriptionRepository::SOURCE_VOTE );
		}
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_by_default_everyone_who_followed_the_merged_request_hears_once_in_the_background(): void {
		MergeService::merge( $this->duplicate, $this->target );

		$this->assertSame( [], $this->emailed(), 'Merging sends nothing itself.' );
		$actions = array_values( $this->waiting_actions( MergeNotifier::HOOK ) );
		$this->assertCount( 1, $actions );
		$this->assertSame( Queue::GROUP, $actions[0]->get_group() );

		$this->assertSame( 1, $this->run_waiting_actions( MergeNotifier::HOOK ) );
		$this->assertSame( [ 'sam@example.com', 'ana@example.com', 'ben@example.com' ], $this->emailed() );
	}

	public function test_the_email_says_where_the_request_went_and_how_to_stop_the_emails(): void {
		$ana = $this->followers[0];
		VoteRepository::add( $this->duplicate, hash( 'sha256', 'u:' . $ana ), $ana );

		MergeService::merge( $this->duplicate, $this->target );
		$this->run_waiting_actions( MergeNotifier::HOOK );

		$emails = $this->emails_to();
		$stop   = Unsubscribe::url( SubscriptionRepository::find( $this->target, $ana )['token'] );
		$this->assertSame( '[Test Site] Night theme was merged into Dark mode', $emails['ana@example.com']->subject );
		$this->assertSame(
			implode(
				"\n",
				[
					'Hi ana,',
					'',
					'A feature request you follow on Test Site asked for the same as another one, so the two were merged.',
					'',
					'"Night theme" was merged into "Dark mode", and your vote now counts there.',
					'',
					'You now follow that request instead, so you will get an email when its status changes:',
					get_permalink( $this->target ),
					'',
					'--',
					'You get this email because you voted for this request.',
					'Stop following it: ' . $stop,
				]
			),
			trim( str_replace( "\r\n", "\n", $emails['ana@example.com']->body ) )
		);
		$this->assertSame( '<' . $stop . '>', iconv_mime_decode_headers( $emails['ana@example.com']->header, 0, 'UTF-8' )['List-Unsubscribe'] );
		$this->assertStringContainsString( '"Night theme" was merged into "Dark mode".' . "\n", str_replace( "\r\n", "\n", $emails['ben@example.com']->body ), 'Ben did not vote.' );
		$this->assertStringNotContainsString( 'your vote', $emails['ben@example.com']->body );
	}

	public function test_only_the_person_who_suggested_it_hears_when_the_site_chose_so(): void {
		Settings::update( Settings::MERGE_EMAIL, Settings::MERGE_EMAIL_SUBMITTER );

		$this->merge_and_send();

		$this->assertSame( [ 'sam@example.com' ], $this->emailed() );
	}

	public function test_nobody_hears_when_the_site_chose_so_but_the_followers_still_move(): void {
		Settings::update( Settings::MERGE_EMAIL, Settings::MERGE_EMAIL_NOBODY );

		MergeService::merge( $this->duplicate, $this->target );

		$this->assertSame( [], $this->waiting_actions( MergeNotifier::HOOK ) );
		$this->assertSame( array_merge( [ $this->submitter ], $this->followers ), SubscriptionRepository::user_ids( $this->target ) );
	}

	public function test_a_board_can_choose_for_itself(): void {
		$board = $this->default_board();

		BoardSettings::update( $board, [ BoardSettings::MERGE_EMAIL => Settings::MERGE_EMAIL_NOBODY ] );
		$this->merge_and_send();
		$this->assertSame( [], $this->emailed(), 'The board says nobody, the site everyone.' );

		Settings::update( Settings::MERGE_EMAIL, Settings::MERGE_EMAIL_NOBODY );
		BoardSettings::update( $board, [ BoardSettings::MERGE_EMAIL => Settings::MERGE_EMAIL_SUBMITTER ] );
		$this->merge_and_send( $this->another_duplicate() );
		$this->assertSame( [ 'sam@example.com' ], $this->emailed(), 'The board says the submitter, the site nobody.' );

		reset_phpmailer_instance();
		BoardSettings::update( $board, [ BoardSettings::MERGE_EMAIL => Settings::MERGE_EMAIL_FOLLOWERS ] );
		$this->merge_and_send( $this->another_duplicate() );
		$this->assertSame( [ 'sam@example.com', 'ana@example.com', 'ben@example.com' ], $this->emailed(), 'The board says everyone, the site nobody.' );

		reset_phpmailer_instance();
		BoardSettings::update( $board, [ BoardSettings::MERGE_EMAIL => BoardSettings::USE_SITE ] );
		$this->merge_and_send( $this->another_duplicate() );
		$this->assertSame( [], $this->emailed(), 'The board takes the site\'s choice again.' );
	}

	public function test_the_choice_of_the_board_the_requests_are_on_counts(): void {
		$other = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		wp_set_object_terms( $this->duplicate, $other, BoardTaxonomy::NAME );
		wp_set_object_terms( $this->target, $other, BoardTaxonomy::NAME );
		BoardSettings::update( $this->default_board(), [ BoardSettings::MERGE_EMAIL => Settings::MERGE_EMAIL_NOBODY ] );
		BoardSettings::update( $other, [ BoardSettings::MERGE_EMAIL => Settings::MERGE_EMAIL_SUBMITTER ] );

		$this->merge_and_send();

		$this->assertSame( [ 'sam@example.com' ], $this->emailed() );
	}

	public function test_the_person_who_merges_is_never_emailed(): void {
		SubscriptionRepository::add( $this->duplicate, $this->moderator, SubscriptionRepository::SOURCE_FOLLOW );
		update_post_meta( $this->duplicate, RequestPostType::META_SUBMITTER_ID, $this->moderator );

		$this->merge_and_send();
		$this->assertNotContains( 'moderator@example.com', $this->emailed() );

		reset_phpmailer_instance();
		Settings::update( Settings::MERGE_EMAIL, Settings::MERGE_EMAIL_SUBMITTER );
		$duplicate = $this->another_duplicate();
		update_post_meta( $duplicate, RequestPostType::META_SUBMITTER_ID, $this->moderator );
		$this->merge_and_send( $duplicate );
		$this->assertSame( [], $this->emailed(), 'Not even when they suggested the request.' );
	}

	public function test_nobody_gets_the_email_twice(): void {
		// Sam suggested the duplicate, follows it and the target, and voted for both.
		SubscriptionRepository::add( $this->target, $this->submitter, SubscriptionRepository::SOURCE_FOLLOW );
		VoteRepository::add( $this->duplicate, hash( 'sha256', 'u:' . $this->submitter ), $this->submitter );
		VoteRepository::add( $this->target, hash( 'sha256', 'u:' . $this->submitter ), $this->submitter );
		$this->merge_and_send();

		$this->assertSame( [ 'sam@example.com', 'ana@example.com', 'ben@example.com' ], $this->emailed() );
	}

	public function test_a_follower_who_stops_following_before_the_email_goes_out_is_not_emailed(): void {
		MergeService::merge( $this->duplicate, $this->target );
		SubscriptionRepository::remove( $this->target, $this->followers[0] );

		$this->run_waiting_actions( MergeNotifier::HOOK );

		$this->assertSame( [ 'sam@example.com', 'ben@example.com' ], $this->emailed() );
	}

	public function test_nothing_goes_out_while_the_site_does_not_email_followers(): void {
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );

		MergeService::merge( $this->duplicate, $this->target );

		$this->assertSame( [], $this->waiting_actions( MergeNotifier::HOOK ) );
		$this->assertSame( array_merge( [ $this->submitter ], $this->followers ), SubscriptionRepository::user_ids( $this->target ), 'Followers move all the same.' );
	}

	public function test_nothing_goes_out_when_the_target_is_no_longer_published(): void {
		MergeService::merge( $this->duplicate, $this->target );
		wp_update_post(
			[
				'ID'          => $this->target,
				'post_status' => 'draft',
			]
		);

		$this->run_waiting_actions( MergeNotifier::HOOK );

		$this->assertSame( [], $this->emailed() );
	}

	public function test_many_people_are_emailed_in_batches_of_50(): void {
		foreach ( range( 900001, 900120 ) as $user_id ) {
			SubscriptionRepository::add( $this->duplicate, $user_id, SubscriptionRepository::SOURCE_VOTE );
		}

		MergeService::merge( $this->duplicate, $this->target );

		$batches = array_map(
			static function ( $action ): int {
				return count( $action->get_args()[2] );
			},
			array_values( $this->waiting_actions( MergeNotifier::HOOK ) )
		);
		$this->assertSame( [ 50, 50, 23 ], $batches );
	}

	public function test_a_theme_can_replace_the_text_of_the_email(): void {
		global $wp_stylesheet_path;

		// A folder that stands in for the active theme, where WordPress looks for templates first.
		wp_set_template_globals();
		$original = $wp_stylesheet_path;
		$theme    = trailingslashit( get_temp_dir() ) . uniqid( 'mwtfrs-theme-' );
		$folder   = $theme . '/feature-request-system/emails';
		wp_mkdir_p( $folder );
		file_put_contents( $folder . '/request-merged.php', '<?php echo esc_html( $args["merged_title"] . " > " . $args["title"] );' );
		$wp_stylesheet_path = $theme;

		try {
			$this->merge_and_send();
		} finally {
			$wp_stylesheet_path = $original;
			unlink( $folder . '/request-merged.php' );
			rmdir( $folder );
			rmdir( dirname( $folder ) );
			rmdir( $theme );
		}

		$this->assertSame( 'Night theme > Dark mode', trim( $this->emails_to()['ana@example.com']->body ) );
	}

	/**
	 * Merges a request into the target and runs the emails.
	 *
	 * @param int $duplicate ID of the request to merge, the usual duplicate by default.
	 */
	private function merge_and_send( int $duplicate = 0 ): void {
		$result = MergeService::merge( $duplicate ? $duplicate : $this->duplicate, $this->target );
		$this->assertIsArray( $result );
		$this->run_waiting_actions( MergeNotifier::HOOK );
	}

	/**
	 * Creates another duplicate of the target, suggested and followed by the same people.
	 */
	private function another_duplicate(): int {
		$duplicate = $this->create_request( [ 'post_title' => 'Night theme again' ] );
		update_post_meta( $duplicate, RequestPostType::META_SUBMITTER_ID, $this->submitter );
		SubscriptionRepository::add( $duplicate, $this->submitter, SubscriptionRepository::SOURCE_AUTHOR );
		foreach ( $this->followers as $follower ) {
			SubscriptionRepository::add( $duplicate, $follower, SubscriptionRepository::SOURCE_VOTE );
		}

		return $duplicate;
	}

	/**
	 * Creates a user named after their login, with an email address made from it.
	 *
	 * @param string $login Login and display name.
	 */
	private function user( string $login ): int {
		return self::factory()->user->create(
			[
				'user_login'   => $login,
				'display_name' => $login,
				'user_email'   => $login . '@example.com',
			]
		);
	}

	/**
	 * Returns the emails sent during the test, by the address they went to.
	 *
	 * @return array<string, object>
	 */
	private function emails_to(): array {
		$emails = [];
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $index => $email ) {
			$emails[ $email['to'][0][0] ] = tests_retrieve_phpmailer_instance()->get_sent( $index );
		}

		return $emails;
	}
}
