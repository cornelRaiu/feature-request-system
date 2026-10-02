<?php
/**
 * Personal data export and erasure tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Privacy\PersonalData;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteRepository;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;

/**
 * What WordPress's privacy tools export and erase for a person, found by their email address.
 */
final class PersonalDataTest extends TestCase {

	private const EMAIL = 'ana@example.com';

	/**
	 * The user the tools look for.
	 *
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$this->user = self::factory()->user->create( [ 'user_email' => self::EMAIL ] );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ VoterIdentity::COOKIE ] );
		parent::tear_down();
	}

	public function test_the_plugin_is_in_wordpresss_export_and_erase_tools(): void {
		$others    = [ 'another-plugin' => [ 'callback' => '__return_empty_array' ] ];
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', $others );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', $others );
		$exporter  = $exporters['feature-request-system'];
		$eraser    = $erasers['feature-request-system'];

		$this->assertArrayHasKey( 'another-plugin', $exporters, 'Next to what other plugins added.' );
		$this->assertArrayHasKey( 'another-plugin', $erasers );

		$this->assertSame( 'Feature Request System', $exporter['exporter_friendly_name'] );
		$this->assertSame( 'Feature Request System', $eraser['eraser_friendly_name'] );
		$this->assertSame( [ 'data' => [], 'done' => true ], call_user_func( $exporter['callback'], 'nobody@example.com', 1 ) );
		$this->assertSame(
			[
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			],
			call_user_func( $eraser['callback'], 'nobody@example.com', 1 )
		);
	}

	public function test_the_export_holds_what_a_user_suggested_voted_for_and_follows(): void {
		wp_set_current_user( $this->user );
		$suggested = SubmissionService::submit(
			$this->default_board(),
			$this->submission(
				[
					'title'       => 'Tom & Jerry mode',
					'description' => 'With "quotes" & [brackets].',
				]
			)
		)['id'];
		$voted     = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$followed  = $this->create_request( [ 'post_title' => 'Export to CSV' ] );
		VoteService::cast( $voted );
		Subscriptions::follow( $followed );
		$this->someone_else_does_the_same( $voted, $followed );

		$export = PersonalData::export( self::EMAIL );

		$this->assertTrue( $export['done'] );
		$this->assertSame(
			[
				'request-' . $suggested => [
					'mwtfrs-requests',
					'Feature requests',
					[
						'Title'        => 'Tom & Jerry mode',
						'Details'      => 'With "quotes" & [brackets].',
						'Suggested on' => $this->date( get_post( $suggested )->post_date_gmt ),
						'Status'       => 'Open',
					],
				],
				'vote-' . $voted        => [
					'mwtfrs-votes',
					'Feature request votes',
					[
						'Request'  => 'Dark mode',
						'Link'     => get_permalink( $voted ),
						'Voted on' => $this->date( current_time( 'mysql', true ) ),
					],
				],
				// Newest first: the request they chose to follow, the one they voted for, the one they suggested.
				'follow-' . $followed   => [
					'mwtfrs-follows',
					'Followed feature requests',
					[
						'Request'         => 'Export to CSV',
						'Link'            => get_permalink( $followed ),
						'Following since' => $this->date( current_time( 'mysql', true ) ),
						'Because'         => 'They chose to follow it',
					],
				],
				'follow-' . $voted      => [
					'mwtfrs-follows',
					'Followed feature requests',
					[
						'Request'         => 'Dark mode',
						'Link'            => get_permalink( $voted ),
						'Following since' => $this->date( current_time( 'mysql', true ) ),
						'Because'         => 'They voted for it',
					],
				],
				'follow-' . $suggested  => [
					'mwtfrs-follows',
					'Followed feature requests',
					[
						'Request'         => 'Tom & Jerry mode',
						'Following since' => $this->date( current_time( 'mysql', true ) ),
						'Because'         => 'They suggested it',
					],
				],
			],
			$this->summary( $export['data'] ),
			'A request that waits for approval has no link.'
		);
	}

	public function test_the_export_says_when_a_user_turned_off_following_by_voting(): void {
		$this->assertSame( [], PersonalData::export( self::EMAIL )['data'] );

		Subscriptions::set_automatic( $this->user, false );

		$this->assertSame(
			[ 'choices-' . $this->user => [ 'mwtfrs-choices', 'Feature request choices', [ 'Follows the requests they vote for or suggest' => 'No' ] ] ],
			$this->summary( PersonalData::export( self::EMAIL )['data'] )
		);
		$this->assertSame( [], PersonalData::export( self::EMAIL, 2 )['data'], 'Once, not on every page.' );
	}

	public function test_the_export_finds_what_a_visitor_suggested_with_that_email_address(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
		wp_set_current_user( 0 );
		$theirs = $this->guest_request( 'Guest idea', 'Robin', 'robin@example.com' );
		$this->guest_request( 'Another guest idea', 'Sam', 'sam@example.com' );
		// Votes without an account belong to nobody the tools can find, also not to this visitor.
		VoteRepository::add( $theirs, hash( 'sha256', 'g:' . str_repeat( 'ab', 16 ) ), 0 );

		$export = $this->summary( PersonalData::export( 'robin@example.com' )['data'] );

		$this->assertSame( [ 'request-' . $theirs ], array_keys( $export ) );
		$this->assertSame( 'Guest idea', $export[ 'request-' . $theirs ][2]['Title'] );
		$this->assertSame( 'Robin', $export[ 'request-' . $theirs ][2]['Name given'] );
		$this->assertSame( 'robin@example.com', $export[ 'request-' . $theirs ][2]['Email given'] );
	}

	public function test_the_export_goes_out_a_page_at_a_time(): void {
		// Votes for requests that no longer exist are enough to fill more than one page.
		foreach ( range( 900001, 900101 ) as $request_id ) {
			VoteRepository::add( $request_id, hash( 'sha256', 'u:' . $this->user ), $this->user );
		}

		$first  = PersonalData::export( self::EMAIL, 1 );
		$second = PersonalData::export( self::EMAIL, 2 );

		$this->assertCount( 100, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame(
			[ 'vote-900101' => [ 'mwtfrs-votes', 'Feature request votes', [ 'Request' => 'A request that has since been deleted', 'Voted on' => $this->date( current_time( 'mysql', true ) ) ] ] ],
			$this->summary( $second['data'] )
		);
	}

	public function test_many_followed_requests_go_out_a_page_at_a_time_newest_first(): void {
		foreach ( range( 900001, 900101 ) as $request_id ) {
			SubscriptionRepository::add( $request_id, $this->user, SubscriptionRepository::SOURCE_FOLLOW );
		}

		$first  = PersonalData::export( self::EMAIL, 1 );
		$second = PersonalData::export( self::EMAIL, 2 );

		$this->assertCount( 100, $first['data'] );
		$this->assertSame( 'follow-900101', $first['data'][0]['item_id'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( [ 'follow-900001' ], wp_list_pluck( $second['data'], 'item_id' ) );
		$this->assertTrue( $second['done'] );
	}

	public function test_many_suggested_requests_go_out_a_page_at_a_time_oldest_first(): void {
		$requests = self::factory()->post->create_many( 101, [ 'post_type' => RequestPostType::NAME ] );
		foreach ( $requests as $request ) {
			update_post_meta( $request, RequestPostType::META_SUBMITTER_ID, $this->user );
		}

		$first  = PersonalData::export( self::EMAIL, 1 );
		$second = PersonalData::export( self::EMAIL, 2 );

		$this->assertCount( 100, $first['data'] );
		$this->assertSame( 'request-' . min( $requests ), $first['data'][0]['item_id'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( [ 'request-' . max( $requests ) ], wp_list_pluck( $second['data'], 'item_id' ) );
		$this->assertTrue( $second['done'] );
	}

	public function test_the_export_gives_dates_in_the_time_and_format_of_the_site(): void {
		global $wpdb;

		update_option( 'timezone_string', 'Europe/Bucharest' );
		$request = $this->create_request();
		VoteRepository::add( $request, hash( 'sha256', 'u:' . $this->user ), $this->user );
		SubscriptionRepository::add( $request, $this->user, SubscriptionRepository::SOURCE_VOTE );
		// Both happened on a winter morning, stored in UTC.
		$wpdb->update( Schema::table( Schema::VOTES ), [ 'created_at' => '2026-01-15 07:30:00' ], [ 'user_id' => $this->user ] );
		$wpdb->update( Schema::table( Schema::SUBSCRIPTIONS ), [ 'created_at' => '2026-01-16 08:45:00' ], [ 'user_id' => $this->user ] );

		$export = $this->summary( PersonalData::export( self::EMAIL )['data'] );

		$this->assertSame( 'January 15, 2026 9:30 am', $export[ 'vote-' . $request ][2]['Voted on'] );
		$this->assertSame( 'January 16, 2026 10:45 am', $export[ 'follow-' . $request ][2]['Following since'] );
	}

	/**
	 * WordPress tells the administrator whether anything was erased.
	 */
	public function test_erasing_says_that_something_was_removed_whichever_one_thing_the_person_had(): void {
		$voter    = self::factory()->user->create( [ 'user_email' => 'voter@example.com' ] );
		$follower = self::factory()->user->create( [ 'user_email' => 'follower@example.com' ] );
		$chooser  = self::factory()->user->create( [ 'user_email' => 'chooser@example.com' ] );
		$request  = $this->create_request();
		VoteRepository::add( $request, hash( 'sha256', 'u:' . $voter ), $voter );
		SubscriptionRepository::add( $request, $follower, SubscriptionRepository::SOURCE_FOLLOW );
		Subscriptions::set_automatic( $chooser, false );

		$votes = PersonalData::erase( 'voter@example.com' );
		$this->assertTrue( $votes['items_removed'] );
		$this->assertCount( 1, $votes['messages'] );

		$follows = PersonalData::erase( 'follower@example.com' );
		$this->assertTrue( $follows['items_removed'] );
		$this->assertSame( [], $follows['messages'] );
		$this->assertSame( 0, SubscriptionRepository::count_of_user( $follower ) );

		$choice = PersonalData::erase( 'chooser@example.com' );
		$this->assertTrue( $choice['items_removed'] );
		$this->assertSame( [], $choice['messages'] );
		$this->assertTrue( Subscriptions::wants_automatic( $chooser ) );
	}

	public function test_erasing_keeps_requests_and_votes_but_cuts_their_tie_to_the_person(): void {
		wp_set_current_user( $this->user );
		$suggested = SubmissionService::submit( $this->default_board(), $this->submission() )['id'];
		$voted     = $this->create_request();
		$followed  = $this->create_request();
		VoteService::cast( $voted );
		Subscriptions::follow( $followed );
		Subscriptions::set_automatic( $this->user, false );
		$other = $this->someone_else_does_the_same( $voted, $followed );

		$result = PersonalData::erase( self::EMAIL );

		$this->assertSame(
			[
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => [
					'Feature requests this person suggested were kept, without the record of who suggested them.',
					'Votes for feature requests were kept, so that vote counts stay correct, without the record of whose they are.',
				],
				'done'           => true,
			],
			$result
		);

		$this->assertSame( 'pending', get_post_status( $suggested ), 'The request stays.' );
		$this->assertSame( '', get_post_meta( $suggested, RequestPostType::META_SUBMITTER_ID, true ) );

		$this->assertSame( 2, VoteCount::sync( $voted ), 'Both votes still count.' );
		$this->assertSame( [], VoteRepository::voted_among( hash( 'sha256', 'u:' . $this->user ), [ $voted ] ), 'But one is no longer the user\'s.' );
		$this->assertSame( [ $voted ], VoteRepository::voted_among( hash( 'sha256', 'u:' . $other ), [ $voted ] ), 'The other voter keeps theirs.' );
		$this->assertSame( [ '0', (string) $other ], $this->voter_ids( $voted ) );

		$this->assertSame( 0, SubscriptionRepository::count_of_user( $this->user ) );
		$this->assertSame( 2, SubscriptionRepository::count_of_user( $other ) );
		$this->assertTrue( Subscriptions::wants_automatic( $this->user ), 'The stored choice is gone too.' );

		$this->assertSame( [], PersonalData::export( self::EMAIL )['data'], 'Nothing is left to export.' );
		$this->assertFalse( PersonalData::erase( self::EMAIL )['items_removed'], 'Nor to erase.' );
	}

	public function test_anonymous_votes_get_keys_that_do_not_lead_back_to_the_user(): void {
		$requests = [ $this->create_request(), $this->create_request(), $this->create_request() ];
		foreach ( $requests as $request ) {
			VoteRepository::add( $request, hash( 'sha256', 'u:' . $this->user ), $this->user );
		}

		$this->assertSame( 3, VoteRepository::anonymise_user( $this->user ) );
		$first  = $this->voter_keys( $requests );
		// The same user voting and being erased again must not get the same keys.
		foreach ( $requests as $request ) {
			VoteRepository::add( $request, hash( 'sha256', 'u:' . $this->user ), $this->user );
		}
		VoteRepository::anonymise_user( $this->user );
		$all = $this->voter_keys( $requests );

		$this->assertCount( 3, array_unique( $first ) );
		$this->assertCount( 6, array_unique( $all ), 'Each anonymous vote has its own key.' );
		foreach ( $all as $key ) {
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $key );
			$this->assertNotSame( hash( 'sha256', 'u:' . $this->user ), $key );
		}
		$this->assertSame( 2, VoteCount::sync( $requests[0] ) );

		// Nor can a key be worked out from the row it is in.
		foreach ( $this->anonymous_votes( $requests ) as $vote ) {
			$this->assertNotSame( hash( 'sha256', (string) $vote->id ), $vote->voter_key );
		}
	}

	public function test_erasing_a_visitor_removes_the_name_and_email_they_gave(): void {
		BoardSettings::update( $this->default_board(), [ BoardSettings::SUBMITTING => BoardSettings::ANYONE ] );
		wp_set_current_user( 0 );
		$theirs = $this->guest_request( 'Guest idea', 'Robin', 'robin@example.com' );
		$other  = $this->guest_request( 'Another guest idea', 'Sam', 'sam@example.com' );
		// A vote without an account hangs on a cookie. The tools cannot tell whose it is, so they leave it alone.
		$guest = hash( 'sha256', 'g:' . str_repeat( 'ab', 16 ) );
		VoteRepository::add( $theirs, $guest, 0 );

		$result = PersonalData::erase( 'robin@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertSame( [ $theirs ], VoteRepository::voted_among( $guest, [ $theirs ] ), 'The guest still has their vote.' );
		$this->assertSame( 'Guest idea', get_post( $theirs )->post_title );
		$this->assertSame( '', get_post_meta( $theirs, RequestPostType::META_SUBMITTER_NAME, true ) );
		$this->assertSame( '', get_post_meta( $theirs, RequestPostType::META_SUBMITTER_EMAIL, true ) );
		$this->assertSame( 'Sam', get_post_meta( $other, RequestPostType::META_SUBMITTER_NAME, true ) );
		$this->assertSame( 'sam@example.com', get_post_meta( $other, RequestPostType::META_SUBMITTER_EMAIL, true ) );
	}

	public function test_erasing_many_requests_goes_a_page_at_a_time_until_none_are_left(): void {
		foreach ( self::factory()->post->create_many( 101, [ 'post_type' => RequestPostType::NAME ] ) as $request ) {
			update_post_meta( $request, RequestPostType::META_SUBMITTER_ID, $this->user );
		}
		VoteRepository::add( 900001, hash( 'sha256', 'u:' . $this->user ), $this->user );

		$first  = PersonalData::erase( self::EMAIL, 1 );
		$second = PersonalData::erase( self::EMAIL, 2 );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $second['done'] );
		$this->assertTrue( $second['items_removed'] );
		$this->assertCount( 1, $second['messages'], 'The votes were dealt with on the first page.' );
		$this->assertSame( [], PersonalData::export( self::EMAIL )['data'] );
	}

	public function test_requests_in_the_trash_and_drafts_are_found_too(): void {
		$ids = [];
		foreach ( [ 'trash', 'draft', 'private' ] as $status ) {
			$ids[ $status ] = $this->create_request( [ 'post_status' => $status ] );
			update_post_meta( $ids[ $status ], RequestPostType::META_SUBMITTER_ID, $this->user );
		}

		$this->assertCount( 3, PersonalData::export( self::EMAIL )['data'] );

		PersonalData::erase( self::EMAIL );

		foreach ( $ids as $status => $id ) {
			$this->assertSame( '', get_post_meta( $id, RequestPostType::META_SUBMITTER_ID, true ), $status );
		}
	}

	/**
	 * Submits a request as a visitor without an account and returns its ID.
	 *
	 * @param string $title Request title.
	 * @param string $name  Name the visitor gives.
	 * @param string $email Email address the visitor gives.
	 */
	private function guest_request( string $title, string $name, string $email ): int {
		return SubmissionService::submit(
			$this->default_board(),
			$this->submission(
				[
					'title' => $title,
					'name'  => $name,
					'email' => $email,
				]
			)
		)['id'];
	}

	/**
	 * Makes another user vote for one request and follow another, then switches back to
	 * the user the test is about.
	 *
	 * @param int $voted    Request the other user votes for.
	 * @param int $followed Request the other user follows.
	 * @return int The other user's ID.
	 */
	private function someone_else_does_the_same( int $voted, int $followed ): int {
		$current = get_current_user_id();
		$other   = self::factory()->user->create();
		wp_set_current_user( $other );

		VoteService::cast( $voted );
		Subscriptions::follow( $followed );

		wp_set_current_user( $current );

		return $other;
	}

	/**
	 * Reduces an export to its items: group ID, group label and fields, by item ID.
	 *
	 * @param array<int, array<string, mixed>> $items Items of an export.
	 * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
	 */
	private function summary( array $items ): array {
		$summary = [];
		foreach ( $items as $item ) {
			$summary[ $item['item_id'] ] = [ $item['group_id'], $item['group_label'], wp_list_pluck( $item['data'], 'value', 'name' ) ];
		}

		return $summary;
	}

	/**
	 * Formats a UTC date and time the way the export does.
	 *
	 * @param string $mysql_gmt Date and time in UTC.
	 */
	private function date( string $mysql_gmt ): string {
		return get_date_from_gmt( $mysql_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Returns the user IDs stored with a request's votes, lowest first.
	 *
	 * @param int $request_id Request ID.
	 * @return string[]
	 */
	private function voter_ids( int $request_id ): array {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE request_id = %d ORDER BY user_id ASC', Schema::table( Schema::VOTES ), $request_id ) );
	}

	/**
	 * Returns the voter keys of the anonymous votes for some requests.
	 *
	 * @param int[] $request_ids Request IDs.
	 * @return string[]
	 */
	private function voter_keys( array $request_ids ): array {
		return wp_list_pluck( $this->anonymous_votes( $request_ids ), 'voter_key' );
	}

	/**
	 * Returns the anonymous votes for some requests: each with its row ID and its voter key.
	 *
	 * @param int[] $request_ids Request IDs.
	 * @return object[]
	 */
	private function anonymous_votes( array $request_ids ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, voter_key FROM %i WHERE user_id = 0 AND request_id IN (' . implode( ',', array_fill( 0, count( $request_ids ), '%d' ) ) . ')',
				array_merge( [ Schema::table( Schema::VOTES ) ], $request_ids )
			)
		);
	}
}
