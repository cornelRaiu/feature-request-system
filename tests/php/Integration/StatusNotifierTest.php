<?php
/**
 * Status change email tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Notifications\Mailer;
use FeatureRequestSystem\Notifications\Queue;
use FeatureRequestSystem\Notifications\StatusNotifier;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Notifications\Unsubscribe;
use FeatureRequestSystem\Settings;

/**
 * A request's followers hear about a new status once, in the background, a few minutes
 * after the change, and only if they still follow the request by then.
 */
final class StatusNotifierTest extends TestCase {

	/**
	 * The moderator who changes the statuses.
	 *
	 * @var int
	 */
	private $moderator = 0;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();

		$this->moderator = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $this->moderator );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_a_status_change_with_120_followers_schedules_3_batches(): void {
		$request = $this->create_request();
		// A batch is a range of subscriptions, so rows without a real user are enough to count batches.
		foreach ( range( 900001, 900120 ) as $user_id ) {
			SubscriptionRepository::add( $request, $user_id, SubscriptionRepository::SOURCE_VOTE );
		}
		$ids = SubscriptionRepository::ids( $request );

		$this->change_status( $request, 'planned' );

		$actions = array_values( $this->waiting_actions( StatusNotifier::HOOK ) );
		$this->assertCount( 3, $actions );

		$notice = $actions[0]->get_args()[1];
		$this->assertSame(
			[
				[ $request, $notice, $ids[0], $ids[49] ],
				[ $request, $notice, $ids[50], $ids[99] ],
				[ $request, $notice, $ids[100], $ids[119] ],
			],
			array_map(
				static function ( $action ): array {
					return $action->get_args();
				},
				$actions
			)
		);

		foreach ( $actions as $action ) {
			$this->assertSame( Queue::GROUP, $action->get_group() );
			$this->assertEqualsWithDelta( time() + 5 * MINUTE_IN_SECONDS, $action->get_schedule()->get_date()->getTimestamp(), 5, 'The emails wait five minutes.' );
		}
		$this->assertSame( [], $this->emailed(), 'Saving the status sends nothing itself.' );
	}

	public function test_every_follower_gets_exactly_one_email_across_the_batches(): void {
		add_filter(
			StatusNotifier::FILTER_BATCH_SIZE,
			static function (): int {
				return 2;
			}
		);
		$request   = $this->create_request();
		$followers = $this->create_followers( $request, 5 );

		$this->change_status( $request, 'planned' );

		$this->assertSame( 3, $this->run_waiting_actions( StatusNotifier::HOOK ) );
		$this->assertSame( $this->emails_of( $followers ), $this->emailed() );
		$this->assertSame( 0, $this->run_waiting_actions( StatusNotifier::HOOK ), 'A batch that ran does not run again.' );
	}

	public function test_the_email_says_what_changed_and_how_to_stop_the_emails(): void {
		$request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$user    = self::factory()->user->create(
			[
				'display_name' => 'Ana Lopez',
				'user_email'   => 'ana@example.com',
			]
		);
		SubscriptionRepository::add( $request, $user, SubscriptionRepository::SOURCE_VOTE );
		$stop_url = Unsubscribe::url( SubscriptionRepository::find( $request, $user )['token'] );
		// A link with an ampersand, as on sites with plain permalinks, must arrive unescaped.
		add_filter(
			'post_type_link',
			static function ( string $url ): string {
				return add_query_arg(
					[
						'from' => 'email',
						'kind' => 'status',
					],
					$url
				);
			}
		);

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$email = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'ana@example.com', $email->to[0][0] );
		$this->assertSame( '[Test Site] Status changed to Planned: Dark mode', $email->subject );
		$this->assertSame(
			implode(
				"\n",
				[
					'Hi Ana Lopez,',
					'',
					'A feature request you follow on Test Site has a new status.',
					'',
					'Dark mode',
					'Status: Planned (was Open)',
					'',
					'Read more and join the discussion:',
					get_permalink( $request ),
					'',
					'--',
					'You get this email because you voted for this request.',
					'Stop following it: ' . $stop_url,
				]
			),
			trim( str_replace( "\r\n", "\n", $email->body ) )
		);
		$this->assertStringEndsWith( 'from=email&kind=status', get_permalink( $request ) );
		$this->assertStringContainsString( 'Content-Type: text/plain', $email->header );

		// With PHP's mail(), the WordPress default, PHPMailer encodes every long header. Decoded, the link is intact.
		$headers = iconv_mime_decode_headers( $email->header, 0, 'UTF-8' );
		$this->assertSame( '<' . $stop_url . '>', $headers['List-Unsubscribe'] );
		$this->assertSame( 'List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post'] );
	}

	public function test_a_site_that_sends_through_smtp_gives_mail_programs_the_unsubscribe_link_as_it_is(): void {
		$request = $this->create_request();
		$user    = $this->create_followers( $request, 1 )[0];
		add_action(
			'phpmailer_init',
			static function ( $phpmailer ): void {
				$phpmailer->Mailer = 'smtp'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
			}
		);

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$header = tests_retrieve_phpmailer_instance()->get_sent()->header;
		$this->assertStringContainsString( 'List-Unsubscribe: <' . Unsubscribe::url( SubscriptionRepository::find( $request, $user )['token'] ) . ">\r\n", $header );
		$this->assertStringContainsString( "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n", $header );
	}

	public function test_the_reason_in_the_email_matches_why_the_user_follows(): void {
		$request = $this->create_request();
		$this->create_followers( $request, 1, SubscriptionRepository::SOURCE_FOLLOW );
		$this->create_followers( $request, 1, SubscriptionRepository::SOURCE_AUTHOR );

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertStringContainsString( 'because you follow this request.', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body );
		$this->assertStringContainsString( 'because you suggested this request.', tests_retrieve_phpmailer_instance()->get_sent( 1 )->body );
	}

	public function test_names_and_titles_arrive_as_typed_in_the_plain_text_email(): void {
		$status = self::factory()->term->create(
			[
				'taxonomy' => StatusTaxonomy::NAME,
				'name'     => 'R&D',
			]
		);
		// WordPress stores an ampersand in a title or a term name as an entity.
		$request = $this->create_request( [ 'post_title' => 'Tom &amp; Jerry\'s "night" mode' ] );
		SubscriptionRepository::add( $request, self::factory()->user->create( [ 'display_name' => 'Zoë & O\'Neil' ] ), SubscriptionRepository::SOURCE_FOLLOW );

		wp_set_object_terms( $request, $status, StatusTaxonomy::NAME );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$email = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( '[Test Site] Status changed to R&D: Tom & Jerry\'s "night" mode', $email->subject );
		$this->assertStringContainsString( "Hi Zoë & O'Neil,", $email->body );
		$this->assertStringContainsString( "Tom & Jerry's \"night\" mode\nStatus: R&D (was Open)", str_replace( "\r\n", "\n", $email->body ) );
	}

	public function test_no_mail_goes_to_someone_who_stopped_following_before_the_emails_went_out(): void {
		$request                             = $this->create_request();
		list( $stays, $by_link, $by_button ) = $this->create_followers( $request, 3 );

		$this->change_status( $request, 'planned' );

		Unsubscribe::process( SubscriptionRepository::find( $request, $by_link )['token'], true, false );
		wp_set_current_user( $by_button );
		Subscriptions::unfollow( $request );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( [ $stays ] ), $this->emailed() );
	}

	public function test_only_the_followers_of_the_request_that_changed_are_emailed(): void {
		$changed = $this->create_request();
		$other   = $this->create_request();
		// Followers of both requests in turn, so a batch's range of subscriptions spans rows of both.
		$followers = [];
		foreach ( range( 1, 3 ) as $unused ) {
			$followers[] = $this->create_followers( $changed, 1 )[0];
			$this->create_followers( $other, 1 );
		}

		$this->change_status( $changed, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( $followers ), $this->emailed() );
	}

	public function test_a_follower_without_an_email_address_is_skipped(): void {
		global $wpdb;

		$request                    = $this->create_request();
		list( $without, $reachable ) = $this->create_followers( $request, 2 );
		$wpdb->update( $wpdb->users, [ 'user_email' => '' ], [ 'ID' => $without ] );
		clean_user_cache( $without );
		$failures = 0;
		add_action(
			'wp_mail_failed',
			static function () use ( &$failures ): void {
				++$failures;
			}
		);

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( [ $reachable ] ), $this->emailed() );
		$this->assertSame( 0, $failures, 'No email is attempted, so none fails.' );
	}

	/**
	 * On a network of sites, a user can be deleted without this site's table hearing of it.
	 */
	public function test_a_follower_whose_account_no_longer_exists_is_skipped_and_forgotten(): void {
		$request  = $this->create_request();
		$follower = $this->create_followers( $request, 1 )[0];
		SubscriptionRepository::add( $request, 999999, SubscriptionRepository::SOURCE_VOTE );
		$this->assertSame( 2, SubscriptionRepository::count( $request ) );

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( [ $follower ] ), $this->emailed() );
		$this->assertSame( 1, SubscriptionRepository::count( $request ) );
		$this->assertNull( SubscriptionRepository::find( $request, 999999 ) );
	}

	public function test_someone_who_follows_after_the_change_is_not_told_about_it(): void {
		$request = $this->create_request();
		$before  = $this->create_followers( $request, 2 );

		$this->change_status( $request, 'planned' );
		$this->create_followers( $request, 2 );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( $before ), $this->emailed() );
	}

	public function test_whoever_changed_the_status_gets_no_email_about_it(): void {
		$request  = $this->create_request();
		$follower = $this->create_followers( $request, 1 );
		SubscriptionRepository::add( $request, $this->moderator, SubscriptionRepository::SOURCE_FOLLOW );

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( $follower ), $this->emailed() );
	}

	public function test_a_change_that_is_corrected_in_time_is_never_announced(): void {
		$request = $this->create_request();
		$this->create_followers( $request, 2 );

		$this->change_status( $request, 'planned' );
		$this->change_status( $request, 'open' );

		$this->assertSame( 1, $this->run_waiting_actions( StatusNotifier::HOOK ), 'The correction schedules nothing.' );
		$this->assertSame( [], $this->emailed() );
	}

	public function test_changes_in_a_row_become_one_email_from_the_first_status_to_the_last(): void {
		$request   = $this->create_request();
		$followers = $this->create_followers( $request, 2 );

		$this->change_status( $request, 'under-review' );
		$this->change_status( $request, 'planned' );
		$this->change_status( $request, 'in-progress' );

		$this->assertSame( 3, $this->run_waiting_actions( StatusNotifier::HOOK ) );
		$this->assertSame( $this->emails_of( $followers ), $this->emailed(), 'The batches of the two replaced announcements send nothing.' );
		$this->assertStringContainsString( 'Status: In Progress (was Open)', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_a_change_after_the_emails_went_out_is_announced_by_itself(): void {
		add_filter( StatusNotifier::FILTER_DELAY, '__return_zero' );
		$request  = $this->create_request();
		$follower = $this->create_followers( $request, 1 );

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );
		$this->change_status( $request, 'shipped' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( [ $follower[0], $follower[0] ] ), $this->emailed() );
		$this->assertStringContainsString( 'Status: Planned (was Open)', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body );
		$this->assertStringContainsString( 'Status: Shipped (was Planned)', tests_retrieve_phpmailer_instance()->get_sent( 1 )->body );
	}

	public function test_the_wait_can_be_changed_or_removed_with_a_filter(): void {
		$request = $this->create_request();
		$this->create_followers( $request, 1 );
		$seconds = 3600;
		add_filter(
			StatusNotifier::FILTER_DELAY,
			static function () use ( &$seconds ): int {
				return $seconds;
			}
		);

		$this->change_status( $request, 'planned' );
		$this->assertSame( 3600, StatusNotifier::delay() );
		$this->assertEqualsWithDelta( time() + 3600, $this->due( StatusNotifier::HOOK ), 5 );

		$seconds = -20;
		$this->assertSame( 0, StatusNotifier::delay(), 'The emails can never be due in the past.' );
	}

	public function test_code_can_keep_a_change_quiet_and_that_also_drops_what_was_waiting(): void {
		$request  = $this->create_request();
		$follower = $this->create_followers( $request, 1 );
		$asked    = [];
		$quiet    = false;
		add_filter(
			StatusNotifier::FILTER_NOTIFY,
			static function ( bool $notify, int $request_id, $old, $new ) use ( &$asked, &$quiet ): bool {
				$asked[] = [ $request_id, $old->slug, $new->slug ];

				return $quiet ? false : $notify;
			},
			10,
			4
		);

		$this->change_status( $request, 'planned' );
		$quiet = true;
		$this->change_status( $request, 'shipped' );

		// Quietly back to the status whose emails were waiting: they stay dropped.
		$this->change_status( $request, 'planned' );

		$this->assertSame( [ [ $request, 'open', 'planned' ], [ $request, 'planned', 'shipped' ], [ $request, 'shipped', 'planned' ] ], $asked );
		$this->assertSame( 1, $this->run_waiting_actions( StatusNotifier::HOOK ), 'The quiet changes schedule nothing.' );
		$this->assertSame( [], $this->emailed(), 'The change that was waiting is not announced either.' );

		$quiet = false;
		$this->change_status( $request, 'declined' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( $this->emails_of( $follower ), $this->emailed() );
		$this->assertStringContainsString( 'Status: Declined (was Planned)', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_work_handed_over_before_action_scheduler_is_ready_waits_for_it(): void {
		global $wp_actions;

		$fired = $wp_actions['action_scheduler_init'];
		unset( $wp_actions['action_scheduler_init'] );

		Queue::schedule( time() + 60, StatusNotifier::HOOK, [ 1, 'early', 1, 1 ] );
		$this->assertSame( [], $this->waiting_actions( StatusNotifier::HOOK ) );

		do_action( 'action_scheduler_init' );
		$wp_actions['action_scheduler_init'] = $fired;

		$this->assertCount( 1, $this->waiting_actions( StatusNotifier::HOOK ) );
	}

	public function test_nothing_is_scheduled_when_there_is_nobody_to_tell(): void {
		$without_followers = $this->create_request();
		$pending           = $this->create_request( [ 'post_status' => 'pending' ] );
		$emails_off        = $this->create_request();
		$this->create_followers( $pending, 1 );
		$this->create_followers( $emails_off, 1 );

		$this->change_status( $without_followers, 'planned' );
		$this->change_status( $pending, 'planned' );
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$this->change_status( $emails_off, 'planned' );

		$this->assertSame( [], $this->waiting_actions( StatusNotifier::HOOK ) );
	}

	public function test_emails_that_wait_are_dropped_when_they_are_out_of_date_by_the_time_they_are_due(): void {
		$trashed    = $this->create_request();
		$emails_off = $this->create_request();
		$status     = self::factory()->term->create( [ 'taxonomy' => StatusTaxonomy::NAME ] );
		$removed    = $this->create_request();
		$this->create_followers( $trashed, 1 );
		$this->create_followers( $removed, 1 );
		$this->create_followers( $emails_off, 1 );

		$this->change_status( $trashed, 'planned' );
		wp_trash_post( $trashed );
		$this->assertSame( 1, $this->run_waiting_actions( StatusNotifier::HOOK ) );
		$this->assertSame( [], $this->emailed(), 'The request is no longer public.' );

		wp_set_object_terms( $removed, $status, StatusTaxonomy::NAME );
		wp_delete_term( $status, StatusTaxonomy::NAME );
		$this->assertSame( 1, $this->run_waiting_actions( StatusNotifier::HOOK ) );
		$this->assertSame( [], $this->emailed(), 'The new status was deleted, which moved the request back.' );

		$this->change_status( $emails_off, 'planned' );
		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$this->assertSame( 1, $this->run_waiting_actions( StatusNotifier::HOOK ) );
		$this->assertSame( [], $this->emailed(), 'The site turned the emails off.' );
	}

	public function test_each_follower_is_emailed_in_the_language_of_their_account(): void {
		$request = $this->create_request();
		$german  = self::factory()->user->create( [ 'locale' => 'de_DE' ] );
		$default = self::factory()->user->create();
		SubscriptionRepository::add( $request, $german, SubscriptionRepository::SOURCE_FOLLOW );
		SubscriptionRepository::add( $request, $default, SubscriptionRepository::SOURCE_FOLLOW );
		$locales = [];
		add_filter(
			Mailer::FILTER,
			static function ( array $email ) use ( &$locales ): array {
				$locales[] = determine_locale();

				return $email;
			}
		);

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( [ 'de_DE', 'en_US' ], $locales );
		$this->assertSame( 'en_US', determine_locale(), 'The language is switched back after each email.' );
	}

	public function test_code_can_change_an_email_or_stop_it(): void {
		$request                = $this->create_request();
		list( $changed, $none ) = $this->create_followers( $request, 2 );
		$seen                   = [];
		add_filter(
			Mailer::FILTER,
			static function ( array $email, string $template, $post, $user ) use ( &$seen, $none ) {
				$seen[] = [ $template, $post->ID, $user->ID ];
				if ( $user->ID === $none ) {
					return false;
				}
				$email['subject'] = 'Good news';

				return $email;
			},
			10,
			4
		);

		$this->change_status( $request, 'planned' );
		$this->run_waiting_actions( StatusNotifier::HOOK );

		$this->assertSame( [ [ 'status-changed', $request, $changed ], [ 'status-changed', $request, $none ] ], $seen );
		$this->assertSame( $this->emails_of( [ $changed ] ), $this->emailed() );
		$this->assertSame( 'Good news', tests_retrieve_phpmailer_instance()->get_sent()->subject );
	}

	public function test_a_theme_can_replace_the_text_of_the_email_and_gets_its_values_as_plain_text(): void {
		global $wp_stylesheet_path;

		// WordPress stores each of these with the ampersand as an entity.
		update_option( 'blogname', 'R&D Team' );
		$statuses = [];
		foreach ( [ 'Q&A', 'R&D' ] as $name ) {
			$statuses[] = self::factory()->term->create(
				[
					'taxonomy' => StatusTaxonomy::NAME,
					'name'     => $name,
				]
			);
		}
		$request = $this->create_request( [ 'post_title' => 'Tom &amp; <em>Jerry</em>' ] );
		wp_set_object_terms( $request, $statuses[0], StatusTaxonomy::NAME );
		SubscriptionRepository::add( $request, self::factory()->user->create( [ 'display_name' => 'Ann & Bob' ] ), SubscriptionRepository::SOURCE_FOLLOW );

		// A folder that stands in for the active theme, where WordPress looks for templates first.
		wp_set_template_globals();
		$original = $wp_stylesheet_path;
		$theme    = trailingslashit( get_temp_dir() ) . uniqid( 'mwtfrs-theme-' );
		$folder   = $theme . '/feature-request-system/emails';
		wp_mkdir_p( $folder );
		// Text that was still stored with entities would come out of strtoupper() as "&AMP;".
		file_put_contents(
			$folder . '/status-changed.php',
			'<?php echo esc_html( strtoupper( implode( " | ", [ $args["name"], $args["site"], $args["title"], $args["status"], $args["old_status"] ] ) ) ); ?> <b>Thanks</b> &amp; bye'
		);
		$wp_stylesheet_path = $theme;

		try {
			wp_set_object_terms( $request, $statuses[1], StatusTaxonomy::NAME );
			$this->run_waiting_actions( StatusNotifier::HOOK );
		} finally {
			$wp_stylesheet_path = $original;
			unlink( $folder . '/status-changed.php' );
			rmdir( $folder );
			rmdir( dirname( $folder ) );
			rmdir( $theme );
		}

		$email = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'ANN & BOB | R&D TEAM | TOM & JERRY | R&D | Q&A Thanks & bye', trim( $email->body ) );
		$this->assertSame( '[R&D Team] Status changed to R&D: Tom & Jerry', $email->subject );
	}

	/**
	 * Changes a request's status, as any screen or code does.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $slug       Slug of the new status.
	 */
	private function change_status( int $request_id, string $slug ): void {
		wp_set_object_terms( $request_id, $this->status( $slug ), StatusTaxonomy::NAME );
	}

	/**
	 * Returns the email addresses of users.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return string[]
	 */
	private function emails_of( array $user_ids ): array {
		return array_map(
			static function ( int $user_id ): string {
				return get_userdata( $user_id )->user_email;
			},
			$user_ids
		);
	}

	/**
	 * Returns when the first waiting action of a hook is due, as a timestamp.
	 *
	 * @param string $hook Hook the action runs.
	 */
	private function due( string $hook ): int {
		$actions = array_values( $this->waiting_actions( $hook ) );

		return $actions[0]->get_schedule()->get_date()->getTimestamp();
	}
}
