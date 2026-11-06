<?php
/**
 * Tests of what is kept about visitors who confirm their email, and how it goes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Guests\Guests;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Install\Uninstaller;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Unsubscribe;
use FeatureRequestSystem\Privacy\PersonalData;
use FeatureRequestSystem\Privacy\PolicyText;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Settings;
use WP_REST_Request;

/**
 * WordPress's privacy tools find a confirmed guest by their address, the policy text
 * names the cookie, and uninstalling removes the guests table.
 */
final class GuestPrivacyTest extends TestCase {

	use GuestHelpers;

	/**
	 * A request on the default board.
	 *
	 * @var int
	 */
	private $request = 0;

	/**
	 * The guest the tools look for.
	 *
	 * @var int
	 */
	private $guest = 0;

	public function set_up(): void {
		parent::set_up();
		$this->forget_visitor();
		$this->board_rules( BoardSettings::VERIFIED, BoardSettings::VERIFIED );
		Settings::update( Settings::NOTIFY_NEW_REQUESTS, false );

		$this->request = $this->create_request( [ 'post_title' => 'Dark mode' ] );
		$this->guest   = $this->become_guest( 'jane@example.com', 'Jane Doe' );
		$this->rest_vote( 'POST', $this->request );
	}

	public function tear_down(): void {
		$this->forget_visitor();
		parent::tear_down();
	}

	public function test_the_export_finds_the_guest_by_their_address_in_any_case(): void {
		$suggested = $this->suggest( 'Calendar view' );
		$waiting   = $this->create_request( [ 'post_title' => 'Export to CSV' ] );
		unset( $_COOKIE[ Guests::COOKIE ] );
		$this->ask_for_link( 'jane@example.com', $waiting );

		$export = PersonalData::export( 'Jane@Example.com' );
		$groups = [];
		foreach ( $export['data'] as $item ) {
			$groups[ $item['group_id'] ][ $item['item_id'] ] = wp_list_pluck( $item['data'], 'value', 'name' );
		}

		$this->assertTrue( $export['done'] );
		$this->assertSame( 'jane@example.com', $groups['mwtfrs-guest'][ 'guest-' . $this->guest ]['Email address'] );
		$this->assertSame( 'Jane Doe', $groups['mwtfrs-guest'][ 'guest-' . $this->guest ]['Name'] );
		$this->assertArrayHasKey( 'Confirmed on', $groups['mwtfrs-guest'][ 'guest-' . $this->guest ] );
		$this->assertSame( 'Your vote for "Export to CSV" is added.', $groups['mwtfrs-guest'][ 'guest-' . $this->guest ]['Waiting for the confirmation'] );
		$this->assertSame( 'Dark mode', $groups['mwtfrs-votes'][ 'guest-vote-' . $this->request ]['Request'] );
		$this->assertArrayHasKey( 'guest-follow-' . $this->request, $groups['mwtfrs-follows'] );
		$this->assertArrayHasKey( 'guest-follow-' . $suggested, $groups['mwtfrs-follows'] );
		$this->assertSame( 'Calendar view', $groups['mwtfrs-requests'][ 'request-' . $suggested ]['Title'] );
	}

	public function test_a_user_and_a_guest_with_one_address_who_voted_for_one_request_are_both_exported(): void {
		$this->forget_visitor();
		wp_set_current_user( self::factory()->user->create( [ 'user_email' => 'jane@example.com' ] ) );
		$this->rest_vote( 'POST', $this->request );

		$ids = wp_list_pluck( wp_list_filter( PersonalData::export( 'jane@example.com' )['data'], [ 'group_id' => 'mwtfrs-votes' ] ), 'item_id' );

		$this->assertEqualsCanonicalizing( [ 'vote-' . $this->request, 'guest-vote-' . $this->request ], $ids, 'The export would merge two items with one ID into one.' );
	}

	public function test_the_export_says_when_a_guest_stopped_every_email(): void {
		$token = SubscriptionRepository::find_guest( $this->request, $this->guest )['token'];
		Unsubscribe::process( $token, true, true );

		$items = wp_list_filter( PersonalData::export( 'jane@example.com' )['data'], [ 'group_id' => 'mwtfrs-guest' ] );

		$this->assertCount( 1, $items );
		$this->assertSame( 'No', wp_list_pluck( reset( $items )['data'], 'value', 'name' )['Follows the requests they vote for or suggest'] );
	}

	public function test_erasing_forgets_the_guest_and_keeps_the_counts(): void {
		$suggested = $this->suggest( 'Calendar view' );

		$result = PersonalData::erase( 'jane@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertNull( $this->guest( 'jane@example.com' ) );
		$this->assertSame( 0, Guests::current_id(), 'The cookie no longer stands for anyone.' );
		$this->assertSame( 0, SubscriptionRepository::count_of_guest( $this->guest ) );
		$this->assertSame( 0, SubscriptionRepository::count( $this->request ) );

		$rows = $this->vote_rows_of( $this->request );
		$this->assertCount( 1, $rows, 'The vote stays, so the count stays correct.' );
		$this->assertNotSame( hash( 'sha256', 'e:' . $this->guest ), $rows[0]->voter_key );
		$this->assertSame( 1, (int) get_post_meta( $this->request, RequestPostType::META_VOTES, true ) );

		$this->assertNotNull( get_post( $suggested ) );
		foreach ( [ RequestPostType::META_SUBMITTER_NAME, RequestPostType::META_SUBMITTER_EMAIL, RequestPostType::META_SUBMITTER_GUEST ] as $key ) {
			$this->assertSame( '', get_post_meta( $suggested, $key, true ), $key );
		}

		$this->assertSame( [], PersonalData::export( 'jane@example.com' )['data'] );
	}

	public function test_erasing_a_user_leaves_a_guest_with_another_address_alone(): void {
		self::factory()->user->create( [ 'user_email' => 'john@example.com' ] );

		PersonalData::erase( 'john@example.com' );

		$this->assertNotNull( $this->guest( 'jane@example.com' ) );
		$this->assertSame( hash( 'sha256', 'e:' . $this->guest ), $this->vote_rows_of( $this->request )[0]->voter_key );
	}

	public function test_the_policy_text_names_the_guest_cookie(): void {
		$text = PolicyText::html();

		$this->assertStringContainsString( Guests::COOKIE, $text );
		$this->assertStringContainsString( 'confirm your email address', $text );
		$this->assertStringContainsString( 'for one year', $text );
	}

	public function test_uninstalling_with_the_opt_in_removes_the_guests_table(): void {
		$drops = [];
		add_filter(
			'query',
			static function ( string $query ) use ( &$drops ): string {
				if ( preg_match( '/^\s*DROP\s+(TEMPORARY\s+)?TABLE/i', $query ) ) {
					$drops[] = $query;
				}

				return $query;
			},
			99
		);
		Settings::update( Settings::DELETE_DATA_ON_UNINSTALL, true );

		Uninstaller::run();

		$this->assertCount( 1, preg_grep( '/' . preg_quote( Schema::table( Schema::GUESTS ), '/' ) . '/', $drops ) );
	}

	/**
	 * Suggests a feature as the confirmed guest, and returns its ID.
	 *
	 * @param string $title Title.
	 */
	private function suggest( string $title ): int {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/boards/' . $this->default_board() . '/requests' );
		$request->set_header( 'Origin', home_url() );
		$request->set_body_params(
			$this->submission(
				[
					'title' => $title,
					'name'  => 'Jane Doe',
					'email' => 'jane@example.com',
				]
			)
		);

		return (int) rest_do_request( $request )->get_data()['id'];
	}
}
