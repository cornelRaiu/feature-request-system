<?php
/**
 * Merge REST API tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Merging\MergedStatus;
use FeatureRequestSystem\Merging\MergeService;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Privacy\PersonalData;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Voting\VoteRepository;
use FeatureRequestSystem\Voting\VoteService;
use WP_Posts_List_Table;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Merging a duplicate through POST /mwtfrs/v1/requests/{id}/merge: votes, followers,
 * counts, the merged status, the refusals and who may do it.
 */
final class MergeApiTest extends TestCase {

	/**
	 * The moderator who merges.
	 *
	 * @var int
	 */
	private $moderator = 0;

	public function set_up(): void {
		parent::set_up();
		$this->moderator = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $this->moderator );
	}

	public function test_votes_move_to_the_target_and_a_voter_of_both_keeps_one_vote(): void {
		$duplicate = $this->create_request();
		$target    = $this->create_request();
		list( $ana, $ben ) = self::factory()->user->create_many( 2 );
		$this->add_vote( $duplicate, $ana );
		$this->add_vote( $duplicate, $ben );
		$this->add_guest_vote( $duplicate, 'first-guest' );
		$this->add_guest_vote( $duplicate, 'both-guest' );
		$this->add_vote( $target, $ben );
		$this->add_guest_vote( $target, 'both-guest' );
		$this->add_guest_vote( $target, 'target-guest' );

		$response = $this->merge( $duplicate, $target );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'id'          => $duplicate,
				'merged_into' => $target,
				'votes'       => 5,
				'url'         => get_permalink( $target ),
			],
			$response->get_data()
		);
		$this->assertSame( 5, VoteRepository::count( $target ), 'Ana, Ben, and three guests: Ben and one guest voted for both.' );
		$this->assertSame( 0, VoteRepository::count( $duplicate ) );
		$this->assertSame( '5', get_post_meta( $target, RequestPostType::META_VOTES, true ) );
		$this->assertSame( '0', get_post_meta( $duplicate, RequestPostType::META_VOTES, true ) );
		$this->assertTrue( VoteRepository::user_voted( $target, $ana ) );
	}

	public function test_followers_move_and_keep_the_stronger_reason(): void {
		$duplicate = $this->create_request();
		$target    = $this->create_request();
		list( $chose, $voted_both, $voted_here_chose_there, $suggested_here_voted_there ) = self::factory()->user->create_many( 4 );
		SubscriptionRepository::add( $duplicate, $chose, SubscriptionRepository::SOURCE_FOLLOW );
		SubscriptionRepository::add( $duplicate, $voted_both, SubscriptionRepository::SOURCE_VOTE );
		SubscriptionRepository::add( $target, $voted_both, SubscriptionRepository::SOURCE_VOTE );
		SubscriptionRepository::add( $duplicate, $voted_here_chose_there, SubscriptionRepository::SOURCE_VOTE );
		SubscriptionRepository::add( $target, $voted_here_chose_there, SubscriptionRepository::SOURCE_FOLLOW );
		SubscriptionRepository::add( $duplicate, $suggested_here_voted_there, SubscriptionRepository::SOURCE_AUTHOR );
		SubscriptionRepository::add( $target, $suggested_here_voted_there, SubscriptionRepository::SOURCE_VOTE );
		$token = SubscriptionRepository::find( $duplicate, $chose )['token'];

		$this->merge( $duplicate, $target );

		$this->assertSame(
			[
				$chose                      => SubscriptionRepository::SOURCE_FOLLOW,
				$voted_both                 => SubscriptionRepository::SOURCE_VOTE,
				$voted_here_chose_there     => SubscriptionRepository::SOURCE_FOLLOW,
				$suggested_here_voted_there => SubscriptionRepository::SOURCE_AUTHOR,
			],
			$this->reasons( $target )
		);
		$this->assertSame( [], SubscriptionRepository::user_ids( $duplicate ) );
		$this->assertSame( $token, SubscriptionRepository::find( $target, $chose )['token'], 'Links in emails sent before keep working.' );
	}

	public function test_the_person_who_suggested_the_duplicate_follows_the_target(): void {
		$submitter = self::factory()->user->create();
		$duplicate = $this->create_request();
		$target    = $this->create_request();
		update_post_meta( $duplicate, RequestPostType::META_SUBMITTER_ID, $submitter );

		$this->merge( $duplicate, $target );

		$this->assertSame( [ $submitter => SubscriptionRepository::SOURCE_AUTHOR ], $this->reasons( $target ) );
	}

	public function test_a_submitter_who_turned_off_following_by_itself_does_not_come_to_follow(): void {
		$submitter = self::factory()->user->create();
		Subscriptions::set_automatic( $submitter, false );
		$duplicate = $this->create_request();
		$target    = $this->create_request();
		update_post_meta( $duplicate, RequestPostType::META_SUBMITTER_ID, $submitter );

		$this->merge( $duplicate, $target );

		$this->assertSame( [], $this->reasons( $target ) );
	}

	public function test_the_duplicate_is_merged_keeps_its_comments_and_tells_who_followed_it(): void {
		$follower  = self::factory()->user->create();
		$duplicate = $this->create_request( [ 'post_status' => 'pending' ] );
		$target    = $this->create_request();
		SubscriptionRepository::add( $duplicate, $follower, SubscriptionRepository::SOURCE_FOLLOW );
		$comment = self::factory()->comment->create( [ 'comment_post_ID' => $duplicate ] );
		$heard   = [];
		add_action(
			MergeService::ACTION_MERGED,
			static function ( int $request_id, int $target_id, array $followers ) use ( &$heard ): void {
				$heard[] = [ $request_id, $target_id, $followers ];
			},
			10,
			3
		);

		$this->assertSame( 200, $this->merge( $duplicate, $target )->get_status(), 'A duplicate that waits for approval can be merged too.' );

		$this->assertSame( MergedStatus::NAME, get_post_status( $duplicate ) );
		$this->assertSame( (string) $target, get_post_meta( $duplicate, MergedStatus::META_INTO, true ) );
		$this->assertSame( $duplicate, (int) get_comment( $comment )->comment_post_ID );
		$this->assertSame( [ [ $duplicate, $target, [ $follower ] ] ], $heard );
	}

	public function test_merged_requests_are_not_public_and_have_their_own_view_in_the_admin_list(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		set_current_screen( 'edit-' . RequestPostType::NAME );
		$duplicate = $this->create_request();
		$this->create_request();
		$this->merge( $duplicate, $this->create_request() );

		$status = get_post_status_object( MergedStatus::NAME );
		$this->assertFalse( $status->public );
		$this->assertFalse( $status->show_in_admin_all_list, 'Duplicates do not clutter All.' );

		// As edit.php does before it shows the list.
		$_GET['post_type']           = RequestPostType::NAME;
		$GLOBALS['avail_post_stati'] = wp_edit_posts_query( $_GET );
		$table                       = new WP_Posts_List_Table( [ 'screen' => 'edit-' . RequestPostType::NAME ] );
		ob_start();
		$table->views();
		$views = (string) ob_get_clean();
		unset( $_GET['post_type'], $GLOBALS['avail_post_stati'] );

		$this->assertStringContainsString( 'post_status=' . MergedStatus::NAME, $views );
		$this->assertStringContainsString( 'Merged <span class="count">(1)</span>', $views );
		$this->assertStringContainsString( 'All <span class="count">(2)</span>', $views );
	}

	public function test_a_merged_request_stays_merged_but_can_go_to_the_trash_and_back(): void {
		$duplicate = $this->create_request();
		$this->merge( $duplicate, $this->create_request() );

		wp_update_post(
			[
				'ID'          => $duplicate,
				'post_status' => 'publish',
			]
		);
		$this->assertSame( MergedStatus::NAME, get_post_status( $duplicate ), 'Merging cannot be undone.' );

		wp_trash_post( $duplicate );
		$this->assertSame( 'trash', get_post_status( $duplicate ) );

		wp_untrash_post( $duplicate );
		$this->assertSame( MergedStatus::NAME, get_post_status( $duplicate ) );
	}

	public function test_the_privacy_tools_find_a_merged_request_by_the_email_its_visitor_gave(): void {
		$duplicate = $this->create_request( [ 'post_title' => 'Night theme' ] );
		update_post_meta( $duplicate, RequestPostType::META_SUBMITTER_NAME, 'Robin' );
		update_post_meta( $duplicate, RequestPostType::META_SUBMITTER_EMAIL, 'robin@example.com' );
		$this->merge( $duplicate, $this->create_request() );

		$export = PersonalData::export( 'robin@example.com' )['data'];
		$this->assertSame( [ 'request-' . $duplicate ], array_column( $export, 'item_id' ) );

		$this->assertTrue( PersonalData::erase( 'robin@example.com' )['items_removed'] );
		$this->assertSame( '', get_post_meta( $duplicate, RequestPostType::META_SUBMITTER_EMAIL, true ) );
	}

	public function test_nobody_can_vote_for_a_merged_request(): void {
		$duplicate = $this->create_request();
		$this->merge( $duplicate, $this->create_request() );

		$this->assertSame( VoteService::ERROR_NOT_FOUND, VoteService::cast( $duplicate )->get_error_code() );
	}

	public function test_a_request_cannot_be_merged_into_itself(): void {
		$request = $this->create_request();
		$this->add_vote( $request, self::factory()->user->create() );

		$this->assertRefused( 400, MergeService::ERROR_INTO_ITSELF, $this->merge( $request, $request ) );
		$this->assertSame( 'publish', get_post_status( $request ) );
		$this->assertSame( 1, VoteRepository::count( $request ) );
	}

	public function test_a_request_cannot_be_merged_into_a_merged_request(): void {
		$first  = $this->create_request();
		$target = $this->create_request();
		$this->merge( $first, $target );
		$second = $this->create_request();
		$this->add_vote( $second, self::factory()->user->create() );

		$this->assertRefused( 400, MergeService::ERROR_INTO_MERGED, $this->merge( $second, $first ) );
		$this->assertSame( 1, VoteRepository::count( $second ), 'Nothing moved.' );
	}

	public function test_a_request_cannot_be_merged_into_a_request_on_another_board(): void {
		$other_board = self::factory()->term->create( [ 'taxonomy' => BoardTaxonomy::NAME ] );
		$duplicate   = $this->create_request();
		$target      = $this->create_request();
		wp_set_object_terms( $target, $other_board, BoardTaxonomy::NAME );
		$this->add_vote( $duplicate, self::factory()->user->create() );

		$response = $this->merge( $duplicate, $target );

		$this->assertRefused( 400, MergeService::ERROR_OTHER_BOARD, $response );
		$this->assertSame( 'Requests can only be merged into a request on the same board.', $response->get_data()['message'] );
		$this->assertSame( 'publish', get_post_status( $duplicate ) );
		$this->assertSame( 1, VoteRepository::count( $duplicate ) );
	}

	public function test_a_request_is_merged_only_into_a_published_request_that_exists(): void {
		$duplicate = $this->create_request();

		$this->assertRefused( 400, MergeService::ERROR_UNPUBLISHED_TARGET, $this->merge( $duplicate, $this->create_request( [ 'post_status' => 'pending' ] ) ) );
		$this->assertRefused( 400, MergeService::ERROR_INVALID_TARGET, $this->merge( $duplicate, $this->create_request( [ 'post_status' => 'trash' ] ) ) );
		$this->assertRefused( 400, MergeService::ERROR_INVALID_TARGET, $this->merge( $duplicate, self::factory()->post->create() ) );
		$this->assertRefused( 400, MergeService::ERROR_INVALID_TARGET, $this->merge( $duplicate, 999999 ) );
		$this->assertSame( 'publish', get_post_status( $duplicate ) );
	}

	public function test_a_merged_request_cannot_be_merged_again_and_a_missing_one_not_at_all(): void {
		$duplicate = $this->create_request();
		$this->merge( $duplicate, $this->create_request() );

		$this->assertRefused( 409, MergeService::ERROR_ALREADY_MERGED, $this->merge( $duplicate, $this->create_request() ) );
		$this->assertRefused( 404, VoteService::ERROR_NOT_FOUND, $this->merge( self::factory()->post->create(), $this->create_request() ) );
		$this->assertRefused( 404, VoteService::ERROR_NOT_FOUND, $this->merge( $this->create_request( [ 'post_status' => 'trash' ] ), $this->create_request() ) );
	}

	public function test_only_those_who_can_edit_others_requests_may_merge(): void {
		$duplicate = $this->create_request();
		$target    = $this->create_request();

		wp_set_current_user( 0 );
		$this->assertRefused( 401, 'rest_forbidden', $this->merge( $duplicate, $target ) );

		foreach ( [ 'subscriber', 'author' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertRefused( 403, 'rest_forbidden', $this->merge( $duplicate, $target ) );
		}
		$this->assertSame( 'publish', get_post_status( $duplicate ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 200, $this->merge( $duplicate, $target )->get_status() );
	}

	public function test_a_filter_decides_who_may_merge(): void {
		$helper = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		add_filter(
			MergeService::FILTER_CAN_MERGE,
			static function ( bool $allowed, int $user_id ) use ( $helper ): bool {
				return $allowed || $helper === $user_id;
			},
			10,
			2
		);

		wp_set_current_user( $helper );
		$this->assertSame( 200, $this->merge( $this->create_request(), $this->create_request() )->get_status() );

		add_filter( MergeService::FILTER_CAN_MERGE, '__return_false', 20 );
		wp_set_current_user( $this->moderator );
		$this->assertRefused( 403, 'rest_forbidden', $this->merge( $this->create_request(), $this->create_request() ) );
	}

	public function test_the_target_is_required_and_must_be_an_id(): void {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/requests/' . $this->create_request() . '/merge' );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		$request->set_param( 'target', 'dark-mode' );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * Asserts that a merge was refused with a status and an error code.
	 *
	 * @param int              $status   Expected HTTP status.
	 * @param string           $code     Expected error code.
	 * @param WP_REST_Response $response Response to check.
	 */
	private function assertRefused( int $status, string $code, WP_REST_Response $response ): void {
		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/**
	 * Merges a request through the REST API.
	 *
	 * @param int $request_id ID of the duplicate.
	 * @param int $target_id  ID of the request to merge it into.
	 */
	private function merge( int $request_id, int $target_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/requests/' . $request_id . '/merge' );
		$request->set_param( 'target', $target_id );

		return rest_do_request( $request );
	}

	/**
	 * Adds a logged-in user's vote.
	 *
	 * @param int $request_id Request ID.
	 * @param int $user_id    User ID.
	 */
	private function add_vote( int $request_id, int $user_id ): void {
		VoteRepository::add( $request_id, hash( 'sha256', 'u:' . $user_id ), $user_id );
	}

	/**
	 * Adds a guest's vote.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $guest      Name of the guest, which stands for their cookie.
	 */
	private function add_guest_vote( int $request_id, string $guest ): void {
		VoteRepository::add( $request_id, hash( 'sha256', 'g:' . $guest ), 0 );
	}

	/**
	 * Returns why each follower of a request follows it, by user ID.
	 *
	 * @param int $request_id Request ID.
	 * @return array<int, string>
	 */
	private function reasons( int $request_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, source FROM %i WHERE request_id = %d ORDER BY user_id', Schema::table( Schema::SUBSCRIPTIONS ), $request_id ) );

		$reasons = [];
		foreach ( $rows as $row ) {
			$reasons[ (int) $row->user_id ] = (string) $row->source;
		}

		return $reasons;
	}
}
