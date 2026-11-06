<?php
/**
 * CSV export tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\ExportLinks;
use FeatureRequestSystem\Admin\RequestList;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Export\CsvExport;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteRepository;
use WPDieException;

/**
 * "Export to CSV" and "Export voters to CSV" on the requests list: the buttons, the
 * download through admin-post.php, its columns, filters, permissions and formula guard.
 */
final class CsvExportTest extends TestCase {

	private const REQUEST_COLUMNS = [ 'ID', 'Title', 'Status', 'Board', 'Categories', 'Votes', 'Followers', 'Date', 'Link' ];

	private const VOTER_COLUMNS = [ 'Request ID', 'Request', 'Voter', 'Email', 'Voted on' ];

	public function set_up(): void {
		parent::set_up();
		$_GET = [];
	}

	public function tear_down(): void {
		$_GET = [];
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_a_moderator_gets_every_column_with_who_submitted_each_request(): void {
		$this->log_in( 'editor' );
		$category = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Reports',
			]
		);
		$guest    = $this->create_request(
			[
				'post_title' => 'Dark mode &#91;beta&#93;',
				'post_date'  => '2026-11-02 10:30:00',
			]
		);
		wp_set_object_terms( $guest, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $guest, $category, CategoryTaxonomy::NAME );
		update_post_meta( $guest, RequestPostType::META_SUBMITTER_NAME, 'Gina Guest' );
		update_post_meta( $guest, RequestPostType::META_SUBMITTER_EMAIL, 'gina@example.com' );
		$this->votes( $guest, 3 );
		$this->create_followers( $guest, 2 );

		$member = $this->create_request(
			[
				'post_title'  => 'Offline mode',
				'post_status' => 'pending',
				'post_date'   => '2026-11-01 09:00:00',
			]
		);
		$user   = self::factory()->user->create(
			[
				'display_name' => 'Uma User',
				'user_email'   => 'uma@example.com',
			]
		);
		update_post_meta( $member, RequestPostType::META_SUBMITTER_ID, $user );

		$this->assertSame(
			[
				array_merge( self::REQUEST_COLUMNS, [ 'Submitter', 'Submitter email' ] ),
				[ (string) $guest, 'Dark mode [beta]', 'Planned', 'Feature Requests', 'Reports', '3', '2', '2026-11-02 10:30:00', get_permalink( $guest ), 'Gina Guest', 'gina@example.com' ],
				[ (string) $member, 'Offline mode', 'Open', 'Feature Requests', '', '0', '0', '2026-11-01 09:00:00', '', 'Uma User', 'uma@example.com' ],
			],
			$this->export()
		);
	}

	public function test_others_who_can_see_the_list_get_no_names_or_emails(): void {
		$request = $this->create_request();
		update_post_meta( $request, RequestPostType::META_SUBMITTER_NAME, 'Gina Guest' );
		update_post_meta( $request, RequestPostType::META_SUBMITTER_EMAIL, 'gina@example.com' );
		VoteRepository::add( $request, hash( 'sha256', 'u:1' ), self::factory()->user->create( [ 'display_name' => 'Vera Voter' ] ) );
		$this->log_in_as_writer();

		$requests = $this->export();
		$this->assertSame( self::REQUEST_COLUMNS, $requests[0] );
		$this->assertCount( count( self::REQUEST_COLUMNS ), $requests[1] );
		$this->assertStringNotContainsString( 'gina', $this->download() );

		$voters = $this->export( true );
		$this->assertSame( [ 'Request ID', 'Request', 'Voter', 'Voted on' ], $voters[0] );
		$this->assertSame( 'Vera Voter', $voters[1][2] );
		$this->assertStringNotContainsString( '@', $this->download( [ ExportLinks::PARAM => ExportLinks::VOTERS ] ) );
	}

	public function test_only_users_who_can_see_the_list_may_export(): void {
		$this->create_request( [ 'post_title' => 'Secret plans' ] );

		foreach ( [ 'subscriber', 'author' ] as $role ) {
			$this->log_in( $role );
			try {
				$this->download();
				$this->fail( "A user with the role {$role} exported the requests." );
			} catch ( WPDieException $refused ) {
				$this->assertSame( 'You are not allowed to export feature requests.', $refused->getMessage() );
			}
		}
	}

	public function test_filters_can_change_who_may_export_and_who_gets_the_emails(): void {
		$request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		update_post_meta( $request, RequestPostType::META_SUBMITTER_EMAIL, 'gina@example.com' );
		$seen = [];
		add_filter(
			CsvExport::FILTER_CAN_EXPORT,
			static function ( bool $allowed, int $user_id ) use ( &$seen ): bool {
				$seen[] = [ $allowed, $user_id ];

				return ! $allowed;
			},
			10,
			2
		);

		$editor = $this->log_in( 'editor' );
		try {
			$this->download();
			$this->fail( 'The filter did not refuse an editor.' );
		} catch ( WPDieException $refused ) {
			$this->assertSame( 'You are not allowed to export feature requests.', $refused->getMessage() );
		}
		$this->assertSame( [ true, $editor ], $seen[0] );

		$subscriber = $this->log_in( 'subscriber' );
		$this->assertStringContainsString( 'Dark mode', $this->download(), 'The filter let a subscriber export.' );
		$this->assertStringNotContainsString( 'gina@example.com', $this->download() );
		$this->assertContains( [ false, $subscriber ], $seen );

		add_filter(
			CsvExport::FILTER_WITH_EMAILS,
			static function ( bool $allowed, int $user_id ) use ( $subscriber ): bool {
				return $allowed || $subscriber === $user_id;
			},
			10,
			2
		);
		$this->assertStringContainsString( 'gina@example.com', $this->download() );
	}

	public function test_a_link_without_its_nonce_exports_nothing(): void {
		$this->log_in( 'editor' );
		$this->create_request( [ 'post_title' => 'Secret plans' ] );

		foreach ( [ 'forged', wp_create_nonce( 'another-action' ) ] as $nonce ) {
			ob_start();
			try {
				ExportLinks::download( [ '_wpnonce' => $nonce ] );
				$this->fail( 'The export ran without its nonce.' );
			} catch ( WPDieException $refused ) {
				$this->assertStringContainsString( 'The link you followed has expired.', $refused->getMessage() );
			} finally {
				$this->assertStringNotContainsString( 'Secret plans', (string) ob_get_clean() );
			}
		}
	}

	public function test_the_handler_answers_only_logged_in_users(): void {
		$this->assertSame( 10, has_action( 'admin_post_' . ExportLinks::ACTION, [ ExportLinks::class, 'handle' ] ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_' . ExportLinks::ACTION ) );
	}

	public function test_the_export_follows_the_status_board_category_and_search_of_the_list(): void {
		$this->log_in( 'editor' );
		$mobile   = self::factory()->term->create(
			[
				'taxonomy' => BoardTaxonomy::NAME,
				'name'     => 'Mobile app',
				'slug'     => 'mobile-app',
			]
		);
		$reports  = self::factory()->term->create(
			[
				'taxonomy' => CategoryTaxonomy::NAME,
				'name'     => 'Reports',
				'slug'     => 'reports',
			]
		);
		$planned  = $this->create_request( [ 'post_title' => 'Planned on the default board' ] );
		$on_phone = $this->create_request( [ 'post_title' => 'Planned on the phone' ] );
		$reported = $this->create_request( [ 'post_title' => 'Monthly report' ] );
		wp_set_object_terms( $planned, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $on_phone, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $on_phone, $mobile, BoardTaxonomy::NAME );
		wp_set_object_terms( $reported, $reports, CategoryTaxonomy::NAME );

		$this->assertEqualSets( [ $planned, $on_phone ], $this->exported_ids( [ StatusTaxonomy::NAME => 'planned' ] ) );
		$this->assertSame( [ $on_phone ], $this->exported_ids( [ StatusTaxonomy::NAME => 'planned', BoardTaxonomy::NAME => 'mobile-app' ] ) );
		$this->assertSame( [ $reported ], $this->exported_ids( [ CategoryTaxonomy::NAME => 'reports' ] ) );
		$this->assertSame( [ $on_phone ], $this->exported_ids( [ 's' => 'phone' ] ) );
		$this->assertSame( [], $this->exported_ids( [ StatusTaxonomy::NAME => 'no-such-status' ] ), 'As on the list, a filter that matches nothing finds nothing.' );
	}

	public function test_the_export_follows_the_mine_view_of_the_list(): void {
		$writer = $this->log_in_as_writer();
		$own    = $this->create_request( [ 'post_author' => $writer ] );
		$other  = $this->create_request( [ 'post_author' => self::factory()->user->create( [ 'role' => 'editor' ] ) ] );

		$this->assertSame( [ $own ], $this->exported_ids( [ 'author' => (string) $writer ] ) );
		$this->assertEqualSets( [ $own, $other ], $this->exported_ids( [ 'author' => 'nobody' ] ) );

		// The list opens on "Mine" for those who can't edit others' requests, and sets the author itself.
		set_current_screen( 'edit-' . RequestPostType::NAME );
		$_GET = [ 'author' => (string) $writer ];
		preg_match( '#href="([^"]+)"#', $this->buttons( 'top' ), $link );
		parse_str( (string) wp_parse_url( html_entity_decode( $link[1] ), PHP_URL_QUERY ), $args );
		$this->assertSame( (string) $writer, $args['author'] );
	}

	public function test_the_export_follows_the_post_status_of_the_list_and_leaves_out_the_trash(): void {
		$this->log_in( 'editor' );
		$published = $this->create_request();
		$pending   = $this->create_request( [ 'post_status' => 'pending' ] );
		$draft     = $this->create_request( [ 'post_status' => 'draft' ] );
		$trashed   = $this->create_request( [ 'post_status' => 'trash' ] );

		$this->assertEqualSets( [ $published, $pending, $draft ], $this->exported_ids( [] ), 'As in the list\'s "All".' );
		$this->assertSame( [ $pending ], $this->exported_ids( [ 'post_status' => 'pending' ] ) );
		$this->assertSame( [ $trashed ], $this->exported_ids( [ 'post_status' => 'trash' ] ) );
		$this->assertEqualSets( [ $published, $pending, $draft ], $this->exported_ids( [ 'post_status' => 'no-such-status' ] ) );
	}

	public function test_the_export_follows_the_month_and_the_sort_order_of_the_list(): void {
		$this->log_in( 'editor' );
		$october = $this->create_request(
			[
				'post_title' => 'Banana',
				'post_date'  => '2026-10-15 12:00:00',
			]
		);
		$early   = $this->create_request(
			[
				'post_title' => 'Cherry',
				'post_date'  => '2026-11-01 12:00:00',
			]
		);
		$late    = $this->create_request(
			[
				'post_title' => 'Apple',
				'post_date'  => '2026-11-05 12:00:00',
			]
		);
		update_post_meta( $october, RequestPostType::META_VOTES, 9 );
		update_post_meta( $late, RequestPostType::META_VOTES, 4 );

		$this->assertSame( [ $late, $early, $october ], $this->exported_ids( [] ), 'Newest first, as the list.' );
		$this->assertSame( [ $late, $early ], $this->exported_ids( [ 'm' => '202611' ] ) );
		$this->assertSame( [ $late, $october, $early ], $this->exported_ids( [ 'orderby' => 'title', 'order' => 'asc' ] ) );
		$this->assertSame( [ $october, $late, $early ], $this->exported_ids( [ 'orderby' => RequestList::COLUMN_VOTES, 'order' => 'desc' ] ) );
		$this->assertSame( [ $early, $late, $october ], $this->exported_ids( [ 'orderby' => RequestList::COLUMN_VOTES, 'order' => 'asc' ] ) );
	}

	public function test_requests_waiting_for_approval_come_oldest_first_as_on_the_list(): void {
		$this->log_in( 'editor' );
		$newer = $this->create_request(
			[
				'post_status'   => 'pending',
				'post_date'     => '2026-11-05 12:00:00',
				'post_modified' => '2026-11-05 12:00:00',
			]
		);
		$older = $this->create_request(
			[
				'post_status'   => 'pending',
				'post_date'     => '2026-11-01 12:00:00',
				'post_modified' => '2026-11-01 12:00:00',
			]
		);

		$this->assertSame( [ $older, $newer ], $this->exported_ids( [ 'post_status' => 'pending' ] ) );
	}

	public function test_a_user_exports_only_the_private_requests_they_may_read(): void {
		$writer  = $this->log_in_as_writer();
		$own     = $this->create_request(
			[
				'post_status' => 'private',
				'post_author' => $writer,
			]
		);
		$other   = $this->create_request(
			[
				'post_status' => 'private',
				'post_author' => self::factory()->user->create( [ 'role' => 'editor' ] ),
			]
		);
		$visible = $this->create_request();

		$this->assertEqualSets( [ $own, $visible ], $this->exported_ids( [] ) );

		$this->log_in( 'editor' );
		$this->assertEqualSets( [ $own, $other, $visible ], $this->exported_ids( [] ) );
	}

	public function test_cells_that_a_spreadsheet_would_run_get_an_apostrophe(): void {
		$this->log_in( 'editor' );
		$titles = [
			'=HYPERLINK("https://evil.example","Click")' => '"\'=HYPERLINK(""https://evil.example"",""Click"")"',
			'+1 for this'                               => "'+1 for this",
			'-2+3'                                      => "'-2+3",
			'@SUM(A1:A2)'                               => "'@SUM(A1:A2)",
			'Dark mode, please'                         => '"Dark mode, please"',
			'Plain = fine'                              => 'Plain = fine',
		];
		$ids    = [];
		foreach ( array_keys( $titles ) as $title ) {
			$ids[ $title ] = $this->create_request( [ 'post_title' => $title ] );
		}
		$visitor = $this->create_request( [ 'post_title' => 'From a visitor' ] );
		update_post_meta( $visitor, RequestPostType::META_SUBMITTER_NAME, '=cmd|\' /C calc\'!A0' );

		$file = $this->download();

		foreach ( $titles as $title => $cell ) {
			$this->assertStringContainsString( "\r\n" . $ids[ $title ] . ',' . $cell . ',', $file, $title );
		}
		$this->assertStringContainsString( ",'=cmd|' /C calc'!A0,", $file, 'Names a visitor gave too.' );
		// Titles and names lose white space at their ends before they get here, so these come from other code.
		$this->assertSame( "'\tStarts with a tab,\"'\rStarts with a return\",7\r\n", CsvExport::line( [ "\tStarts with a tab", "\rStarts with a return", 7 ] ) );
	}

	public function test_a_long_list_is_exported_in_pages_with_every_request_once(): void {
		$this->log_in( 'editor' );
		$ids = [];
		for ( $i = 0; $i < CsvExport::PAGE_SIZE + 1; $i++ ) {
			// All at the same moment, so only the ID decides where a page ends.
			$ids[] = $this->create_request( [ 'post_date' => '2026-11-01 12:00:00' ] );
		}

		// When the second page is read, the first one is no longer held in memory.
		$cached = [];
		add_filter(
			'the_posts',
			static function ( array $posts ) use ( $ids, &$cached ): array {
				$cached[] = false !== wp_cache_get( $ids[ CsvExport::PAGE_SIZE ], 'posts' );

				return $posts;
			}
		);

		$queries  = get_num_queries();
		$exported = $this->exported_ids( [] );
		$this->assertLessThan( 30, get_num_queries() - $queries, 'Each page is read with a few queries, not a few per request.' );
		$this->assertCount( 2, $cached );
		$this->assertFalse( $cached[1], 'The newest request, on the first page, was let go of before the second page was read.' );

		$this->assertCount( CsvExport::PAGE_SIZE + 1, $exported );
		$this->assertSame( array_reverse( $ids ), $exported );
	}

	public function test_the_voters_export_lists_every_vote_of_the_requests_of_the_list(): void {
		$editor  = $this->log_in( 'editor' );
		$first   = $this->create_request(
			[
				'post_title' => 'Older request',
				'post_date'  => '2026-11-01 12:00:00',
			]
		);
		$second  = $this->create_request(
			[
				'post_title' => 'Newer request',
				'post_date'  => '2026-11-02 12:00:00',
			]
		);
		$elsewhere = $this->create_request( [ 'post_title' => 'Not in the list' ] );
		wp_set_object_terms( $first, $this->status( 'planned' ), StatusTaxonomy::NAME );
		wp_set_object_terms( $second, $this->status( 'planned' ), StatusTaxonomy::NAME );
		$vera    = self::factory()->user->create(
			[
				'display_name' => 'Vera Voter',
				'user_email'   => 'vera@example.com',
			]
		);
		$gone    = self::factory()->user->create();
		$this->vote_at( $first, $vera, '2026-11-03 08:00:00' );
		$this->vote_at( $first, 0, '2026-11-02 08:00:00' );
		$this->vote_at( $second, $gone, '2026-11-04 08:00:00' );
		$this->vote_at( $elsewhere, $editor, '2026-11-04 08:00:00' );
		// A vote whose user no longer exists, as on a network where users are deleted elsewhere.
		$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->users, [ 'ID' => $gone ] );
		clean_user_cache( $gone );

		$this->assertSame(
			[
				self::VOTER_COLUMNS,
				[ (string) $second, 'Newer request', 'A user who has since been deleted', '', get_date_from_gmt( '2026-11-04 08:00:00' ) ],
				[ (string) $first, 'Older request', 'Visitor', '', get_date_from_gmt( '2026-11-02 08:00:00' ) ],
				[ (string) $first, 'Older request', 'Vera Voter', 'vera@example.com', get_date_from_gmt( '2026-11-03 08:00:00' ) ],
			],
			$this->export( true, [ StatusTaxonomy::NAME => 'planned' ] )
		);
	}

	public function test_the_votes_of_a_popular_request_are_read_in_pages(): void {
		$this->log_in( 'editor' );
		$request = $this->create_request();
		$this->votes( $request, CsvExport::PAGE_SIZE + 2 );

		$rows = $this->export( true );

		$this->assertCount( CsvExport::PAGE_SIZE + 3, $rows, 'Every vote once, after the column names.' );
		$this->assertCount( 1, array_unique( array_column( array_slice( $rows, 1 ), 0 ) ) );
	}

	public function test_the_buttons_above_the_list_carry_its_filters(): void {
		$this->log_in_as_writer();
		set_current_screen( 'edit-' . RequestPostType::NAME );
		$_GET = [
			'post_type'          => RequestPostType::NAME,
			StatusTaxonomy::NAME => 'planned',
			's'                  => 'dark mode',
			'paged'              => '3',
		];
		$planned = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		wp_set_object_terms( $planned, $this->status( 'planned' ), StatusTaxonomy::NAME );
		$this->create_request( [ 'post_title' => 'Dark mode, but open' ] );

		$html = $this->buttons( 'top' );

		$this->assertMatchesRegularExpression( '#<a class="button" href="[^"]+">Export to CSV</a> <a class="button" href="[^"]+">Export voters to CSV</a>#', $html );
		preg_match_all( '#href="([^"]+)"#', $html, $links );
		$this->assertCount( 2, $links[1] );

		parse_str( (string) wp_parse_url( html_entity_decode( $links[1][0] ), PHP_URL_QUERY ), $requests );
		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), html_entity_decode( $links[1][0] ) );
		$this->assertSame( ExportLinks::ACTION, $requests['action'] );
		$this->assertSame( 'dark mode', $requests['s'] );
		$this->assertArrayNotHasKey( 'paged', $requests, 'Every page of the list, not the one shown.' );
		$this->assertArrayNotHasKey( ExportLinks::PARAM, $requests );
		$this->assertSame( [ $planned ], array_map( 'intval', array_column( array_slice( $this->rows( $this->download_as_is( $requests ) ), 1 ), 0 ) ) );

		parse_str( (string) wp_parse_url( html_entity_decode( $links[1][1] ), PHP_URL_QUERY ), $voters );
		$this->assertSame( ExportLinks::VOTERS, $voters[ ExportLinks::PARAM ] );
		$this->assertSame( 'planned', $voters[ StatusTaxonomy::NAME ] );
	}

	public function test_the_buttons_show_only_above_the_requests_list_for_those_who_may_export(): void {
		$this->log_in( 'editor' );
		set_current_screen( 'edit-' . RequestPostType::NAME );
		$this->assertSame( '', $this->buttons( 'bottom' ), 'Once, above the list.' );

		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->buttons( 'top' ), 'Not on other lists.' );

		set_current_screen( 'edit-' . RequestPostType::NAME );
		$this->log_in( 'subscriber' );
		$this->assertSame( '', $this->buttons( 'top' ) );
	}

	/**
	 * Logs in as a new user with a role.
	 *
	 * @param string $role Role name.
	 * @return int The user's ID.
	 */
	private function log_in( string $role ): int {
		$user = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $user );

		return $user;
	}

	/**
	 * Logs in as a user who may write requests and see the list, but not edit others' requests.
	 *
	 * @return int The user's ID.
	 */
	private function log_in_as_writer(): int {
		$user = $this->log_in( 'author' );
		$type = get_post_type_object( RequestPostType::NAME );
		wp_get_current_user()->add_cap( $type->cap->edit_posts );

		return $user;
	}

	/**
	 * Downloads an export as the current user, with a valid nonce, and returns the file.
	 *
	 * @param array<string, string> $args Query arguments of the link, besides the nonce.
	 */
	private function download( array $args = [] ): string {
		return $this->download_as_is( array_merge( $args, [ '_wpnonce' => wp_create_nonce( ExportLinks::ACTION ) ] ) );
	}

	/**
	 * Downloads an export with exactly these query arguments and returns the file without its byte order mark.
	 *
	 * @param array<string, mixed> $args Query arguments of the link.
	 */
	private function download_as_is( array $args ): string {
		ob_start();
		try {
			ExportLinks::download( $args );
		} finally {
			$file = (string) ob_get_clean();
		}
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $file );

		return substr( $file, 3 );
	}

	/**
	 * Downloads an export and returns its rows.
	 *
	 * @param bool                  $voters  Whether to export the voters.
	 * @param array<string, string> $filters Filters of the list.
	 * @return array<int, string[]>
	 */
	private function export( bool $voters = false, array $filters = [] ): array {
		if ( $voters ) {
			$filters[ ExportLinks::PARAM ] = ExportLinks::VOTERS;
		}

		return $this->rows( $this->download( $filters ) );
	}

	/**
	 * Downloads the requests export with the list's filters and returns the IDs in it.
	 *
	 * @param array<string, string> $filters Filters of the list.
	 * @return int[]
	 */
	private function exported_ids( array $filters ): array {
		return array_map( 'intval', array_column( array_slice( $this->export( false, $filters ), 1 ), 0 ) );
	}

	/**
	 * Splits a CSV file into rows of cells.
	 *
	 * @param string $csv CSV file.
	 * @return array<int, string[]>
	 */
	private function rows( string $csv ): array {
		$this->assertStringEndsWith( "\r\n", $csv );

		return array_map(
			static function ( string $line ): array {
				return str_getcsv( $line, ',', '"', '' );
			},
			explode( "\r\n", substr( $csv, 0, -2 ) )
		);
	}

	/**
	 * Returns what the requests list prints in its navigation.
	 *
	 * @param string $which Top or bottom.
	 */
	private function buttons( string $which ): string {
		ob_start();
		do_action( 'manage_posts_extra_tablenav', $which );

		return (string) ob_get_clean();
	}

	/**
	 * Adds votes from made-up visitors and stores the count.
	 *
	 * @param int $request_id Request ID.
	 * @param int $count      How many votes.
	 */
	private function votes( int $request_id, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			VoteRepository::add( $request_id, hash( 'sha256', 'g:' . $request_id . ':' . $i ), 0 );
		}
		VoteCount::sync( $request_id );
	}

	/**
	 * Adds a vote cast at a given moment.
	 *
	 * @param int    $request_id Request ID.
	 * @param int    $user_id    User ID, or 0 for a visitor.
	 * @param string $created_at Moment in UTC.
	 */
	private function vote_at( int $request_id, int $user_id, string $created_at ): void {
		$GLOBALS['wpdb']->insert(
			Schema::table( Schema::VOTES ),
			[
				'request_id' => $request_id,
				'voter_key'  => hash( 'sha256', wp_generate_password( 20, false ) ),
				'user_id'    => $user_id,
				'created_at' => $created_at,
			]
		);
	}
}
