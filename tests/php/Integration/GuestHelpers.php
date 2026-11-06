<?php
/**
 * Helpers for tests of visitors who confirm their email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Guests\ConfirmationLink;
use FeatureRequestSystem\Guests\GuestRepository;
use FeatureRequestSystem\Guests\Guests;
use FeatureRequestSystem\Install\Schema;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Voting\VoterIdentity;
use WP_REST_Request;
use WP_REST_Response;
use WPDieException;

/**
 * Sends what a visitor's browser sends, and reads what the plugin emails.
 */
trait GuestHelpers {

	/**
	 * Forgets the visitor: no cookies, no form posts, a page view from one address.
	 */
	protected function forget_visitor(): void {
		unset( $_COOKIE[ Guests::COOKIE ], $_COOKIE[ VoterIdentity::COOKIE ] );
		$_GET                      = [];
		$_POST                     = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.10';
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
		wp_set_current_user( 0 );
		reset_phpmailer_instance();
	}

	/**
	 * Sets who may vote and who may suggest features on the default board.
	 *
	 * @param string $voting     A BoardSettings value.
	 * @param string $submitting A BoardSettings value.
	 */
	protected function board_rules( string $voting, string $submitting = BoardSettings::LOGGED_IN ): void {
		BoardSettings::update(
			$this->default_board(),
			[
				BoardSettings::VOTING     => $voting,
				BoardSettings::SUBMITTING => $submitting,
			]
		);
	}

	/**
	 * Asks for a link for a vote through the REST API, as the form next to the vote button does.
	 *
	 * @param string               $email      Email address.
	 * @param int                  $request_id Request to vote for.
	 * @param array<string, mixed> $fields     Fields to override.
	 * @param array<string, string> $headers   Request headers.
	 */
	protected function ask_for_link( string $email, int $request_id, array $fields = [], array $headers = [ 'Origin' => '' ] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RestApi::NAMESPACE . '/guests/link' );
		$request->set_header( 'Origin', '' === $headers['Origin'] ? home_url() : $headers['Origin'] );
		$request->set_body_params(
			array_merge(
				[
					'email'                => $email,
					'request'              => $request_id,
					SpamGuard::FIELD_TRAP  => '',
					SpamGuard::FIELD_TOKEN => SpamGuard::token( time() - 30 ),
				],
				$fields
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * Casts or retracts the current visitor's vote through the REST API, from a page of this site.
	 *
	 * @param string $method     POST to vote, DELETE to retract.
	 * @param int    $request_id Request ID.
	 */
	protected function rest_vote( string $method, int $request_id ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . RestApi::NAMESPACE . '/requests/' . $request_id . '/vote' );
		$request->set_header( 'Origin', home_url() );

		return rest_do_request( $request );
	}

	/**
	 * Returns the emails sent during the test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function sent_emails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * Returns the token of the newest confirmation link that was emailed, or ''.
	 */
	protected function emailed_token(): string {
		$token = '';
		foreach ( $this->sent_emails() as $email ) {
			if ( preg_match( '/' . ConfirmationLink::PARAM . '=([a-f0-9]{32})/', $email['body'], $match ) ) {
				$token = $match[1];
			}
		}

		return $token;
	}

	/**
	 * Opens a confirmation link, or presses the button on its page, and returns the page.
	 *
	 * @param string $token  Token of the link.
	 * @param bool   $press  Whether to press the button, from a page of this site.
	 * @param string $origin Origin header of the post, when pressing.
	 */
	protected function open_link( string $token, bool $press = false, string $origin = '' ): string {
		$_GET[ ConfirmationLink::PARAM ] = $token;
		$_SERVER['REQUEST_METHOD']       = $press ? 'POST' : 'GET';
		$_SERVER['HTTP_ORIGIN']          = $press ? ( '' !== $origin ? $origin : home_url() ) : '';

		try {
			ConfirmationLink::handle();
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		} finally {
			$_GET                      = [];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			unset( $_SERVER['HTTP_ORIGIN'] );
		}

		$this->fail( 'The link did not end with a page.' );
	}

	/**
	 * Makes the current visitor a confirmed guest, as another plugin's signed link would.
	 *
	 * @param string $email Email address.
	 * @param string $name  Name.
	 * @return int The guest's ID.
	 */
	protected function become_guest( string $email = 'jane@example.com', string $name = 'Jane' ): int {
		return Guests::sign_in( $email, $name );
	}

	/**
	 * Returns the stored guest with an address.
	 *
	 * @param string $email Email address.
	 * @return array<string, mixed>|null
	 */
	protected function guest( string $email ): ?array {
		return GuestRepository::find_by_email( $email );
	}

	/**
	 * Returns the vote rows stored for a request.
	 *
	 * @param int $request_id Request ID.
	 * @return object[]
	 */
	protected function vote_rows_of( int $request_id ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, voter_key FROM %i WHERE request_id = %d', Schema::table( Schema::VOTES ), $request_id ) );
	}

	/**
	 * Moves the time a guest's link was sent into the past.
	 *
	 * @param int $guest_id Guest ID.
	 * @param int $seconds  How long ago it was sent.
	 */
	protected function age_link( int $guest_id, int $seconds ): void {
		global $wpdb;

		$wpdb->update( Schema::table( Schema::GUESTS ), [ 'link_sent_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ], [ 'id' => $guest_id ] );
	}
}
