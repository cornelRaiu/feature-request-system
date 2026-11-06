<?php
/**
 * Visitors who confirm their email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Guests;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Notifications\Mailer;
use FeatureRequestSystem\Security\RateLimiter;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\SubmissionService;
use FeatureRequestSystem\Voting\VoterIdentity;
use FeatureRequestSystem\Voting\VoteService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The rules for visitors who take part without an account by confirming their email,
 * on boards that ask for that.
 *
 * A visitor gives their address when they vote or suggest a feature. The plugin keeps
 * what they started and emails them a link. The link's page has a button; pressing it
 * confirms the address, does what the visitor started, and sets a cookie that tells the
 * site who they are for a year. From then on they vote and suggest straight away, and
 * follow requests as users do.
 *
 * Asking for a link gets the same answer whether or not the address is known, and an
 * address gets at most one link every ten minutes. The secret in a link is never stored,
 * only its hash.
 *
 * @phpstan-import-type Guest from GuestRepository
 */
final class Guests {

	/**
	 * Cookie that tells the site which confirmed guest the visitor is: the guest's ID and a signature.
	 */
	public const COOKIE = 'mwtfrs_guest';

	/**
	 * Seconds a link works after it was sent.
	 */
	public const LINK_LIFETIME = DAY_IN_SECONDS;

	/**
	 * Fewest seconds between two links to one address.
	 */
	public const LINK_INTERVAL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Most actions that can wait for one address to be confirmed.
	 */
	public const MAX_PENDING = 10;

	/**
	 * A waiting vote. Holds the request ID.
	 */
	public const PENDING_VOTE = 'vote';

	/**
	 * A waiting feature request. Holds the board ID and the checked submission.
	 */
	public const PENDING_SUBMIT = 'submit';

	/**
	 * The email address is not valid.
	 */
	public const ERROR_INVALID_EMAIL = 'mwtfrs_guest_email_invalid';

	/**
	 * The visitor asked for too many links. The error data holds retry_after in seconds.
	 */
	public const ERROR_RATE_LIMITED = 'mwtfrs_guest_rate_limited';

	/**
	 * Returns the confirmed guest the current visitor is, or null.
	 *
	 * Logged-in users are never guests: their account is who they are.
	 *
	 * @return array<string, mixed>|null
	 * @phpstan-return Guest|null
	 */
	public static function current(): ?array {
		if ( is_user_logged_in() ) {
			return null;
		}

		$value = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 1 !== preg_match( '/^([1-9][0-9]*)\.([a-f0-9]{64})$/', $value, $parts ) ) {
			return null;
		}

		$guest = GuestRepository::find( (int) $parts[1] );
		if ( ! $guest || '' === $guest['confirmed_at'] || ! hash_equals( self::signature( $guest ), $parts[2] ) ) {
			return null;
		}

		return $guest;
	}

	/**
	 * Returns the ID of the confirmed guest the current visitor is, or 0.
	 */
	public static function current_id(): int {
		$guest = self::current();

		return $guest ? $guest['id'] : 0;
	}

	/**
	 * Marks an address as confirmed and makes the current visitor that guest, without a link.
	 *
	 * For other plugins that know the visitor's address already, such as from a signed
	 * link of their own. The plugin trusts the address, so only call this with one that
	 * was checked.
	 *
	 * @param string $email Email address.
	 * @param string $name  Name of the visitor, or '' to keep the one stored.
	 * @return int The guest's ID, or 0 when the address is not valid.
	 */
	public static function sign_in( string $email, string $name ): int {
		$email = self::normalise_email( $email );
		$guest = '' !== $email ? GuestRepository::find_or_create( $email ) : null;
		if ( ! $guest ) {
			return 0;
		}

		GuestRepository::confirm( $guest['id'], self::name( $name ) );
		self::remember( $guest );

		return $guest['id'];
	}

	/**
	 * Starts a vote that counts once the visitor confirms their address.
	 *
	 * @param string               $email      Email address the visitor gave.
	 * @param int                  $request_id Request to vote for.
	 * @param array<string, mixed> $fields     Posted fields, for the spam checks.
	 * @return true|WP_Error True whatever is known about the address, or why the visitor was refused.
	 */
	public static function start_vote( string $email, int $request_id, array $fields ) {
		// The board must take votes, and the visitor be someone who could vote once confirmed.
		$allowed = VoteService::check( $request_id );
		if ( is_wp_error( $allowed ) && VoteService::ERROR_EMAIL_REQUIRED !== $allowed->get_error_code() ) {
			return $allowed;
		}

		$guard = SpamGuard::check( $fields );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return self::request_link(
			$email,
			[
				'type'    => self::PENDING_VOTE,
				'request' => $request_id,
			]
		);
	}

	/**
	 * Keeps an action for when an address is confirmed, and emails the link that confirms it.
	 *
	 * The answer is the same whether the address is new, waits for confirmation, is
	 * confirmed already or had a link minutes ago. An address gets at most one link every
	 * ten minutes; what is asked for meanwhile waits for the link that was sent.
	 *
	 * @param string               $email  Email address the visitor gave.
	 * @param array<string, mixed> $action What to do once the address is confirmed.
	 * @return true|WP_Error
	 */
	public static function request_link( string $email, array $action ) {
		$email = self::normalise_email( $email );
		if ( '' === $email ) {
			return new WP_Error( self::ERROR_INVALID_EMAIL, __( 'Enter a valid email address.', 'feature-request-system' ), [ 'status' => 400 ] );
		}

		$wait = RateLimiter::attempt( 'link', VoterIdentity::key() );
		if ( $wait ) {
			return new WP_Error(
				self::ERROR_RATE_LIMITED,
				__( 'You have asked for several links in a short time. Try again later.', 'feature-request-system' ),
				[
					'status'      => 429,
					'retry_after' => $wait,
				]
			);
		}

		$guest = GuestRepository::find_or_create( $email );
		if ( ! $guest ) {
			return true;
		}

		// What waits for an expired link is forgotten. The new link starts afresh.
		$pending = self::link_works( $guest ) ? $guest['pending'] : [];
		$pending = self::add_pending( $pending, $action );
		GuestRepository::set_pending( $guest['id'], $pending );

		$token = bin2hex( random_bytes( 16 ) );
		if ( GuestRepository::claim_link( $guest['id'], self::hash( $token ), self::LINK_INTERVAL ) ) {
			ConfirmationLink::send( $guest['email'], $token, $pending );
		}

		return true;
	}

	/**
	 * Confirms a guest's address through their link, makes the current visitor that guest,
	 * and does what waited for the confirmation.
	 *
	 * @param array<string, mixed> $guest Guest whose link was used.
	 * @param string               $token Token from the link.
	 * @phpstan-param Guest $guest
	 * @return list<array{message: string, url: string, label: string}>|null What was done, or null when the link was used already.
	 */
	public static function confirm( array $guest, string $token ): ?array {
		$name = '';
		foreach ( $guest['pending'] as $action ) {
			if ( self::PENDING_SUBMIT === ( $action['type'] ?? '' ) && '' !== (string) ( $action['submission']['name'] ?? '' ) ) {
				$name = self::name( (string) $action['submission']['name'] );
			}
		}

		if ( ! GuestRepository::confirm_link( $guest['id'], self::hash( $token ), $name ) ) {
			return null;
		}

		self::remember( $guest );

		$done = [];
		foreach ( $guest['pending'] as $action ) {
			$done[] = self::complete( $action, $guest['id'] );
		}

		return $done;
	}

	/**
	 * Returns the guest whose link holds a token, or null when no link has it.
	 *
	 * @param string $token Token from the link.
	 * @return array<string, mixed>|null
	 * @phpstan-return Guest|null
	 */
	public static function by_token( string $token ): ?array {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token ) ? GuestRepository::find_by_token_hash( self::hash( $token ) ) : null;
	}

	/**
	 * Whether a guest's link still works: it was sent less than a day ago.
	 *
	 * @param array<string, mixed> $guest Guest.
	 * @phpstan-param Guest $guest
	 */
	public static function link_works( array $guest ): bool {
		$sent = '' !== $guest['link_sent_at'] ? strtotime( $guest['link_sent_at'] . ' UTC' ) : false;

		return false !== $sent && $sent > time() - self::LINK_LIFETIME;
	}

	/**
	 * Whether a guest wants to follow the requests they vote for or suggest. That is so
	 * until they stop every email.
	 *
	 * @param int $guest_id Guest ID.
	 */
	public static function wants_automatic( int $guest_id ): bool {
		$guest = GuestRepository::find( $guest_id );

		return $guest && $guest['auto_follow'];
	}

	/**
	 * Says what will be done, or was asked for, when a guest confirms their address.
	 *
	 * The email that holds the link does not name a request someone suggested: anyone can
	 * ask for a link to any address, and the email would carry their text to it.
	 *
	 * @param array<string, mixed> $action     Waiting action.
	 * @param bool                 $with_title Whether to name the request someone suggested.
	 */
	public static function describe( array $action, bool $with_title ): string {
		if ( self::PENDING_VOTE === ( $action['type'] ?? '' ) ) {
			$request = get_post( (int) ( $action['request'] ?? 0 ) );

			return $request && RequestPostType::NAME === $request->post_type && 'publish' === $request->post_status
				/* translators: %s: request title. */
				? sprintf( __( 'Your vote for "%s" is added.', 'feature-request-system' ), Mailer::plain( $request->post_title ) )
				: __( 'Your vote is added.', 'feature-request-system' );
		}

		$title = (string) ( $action['submission']['title'] ?? '' );

		return $with_title && '' !== $title
			/* translators: %s: title of the feature request. */
			? sprintf( __( 'Your feature request "%s" is sent.', 'feature-request-system' ), $title )
			: __( 'Your feature request is sent.', 'feature-request-system' );
	}

	/**
	 * Cleans an email address, or returns '' when it is not valid.
	 *
	 * Addresses are kept in lower case, so that one person has one row whatever they type.
	 *
	 * @param string $email Email address as given.
	 */
	public static function normalise_email( string $email ): string {
		$email = trim( $email );
		if ( strlen( $email ) > SubmissionService::MAX_EMAIL || ! is_email( $email ) ) {
			return '';
		}

		return strtolower( sanitize_email( $email ) );
	}

	/**
	 * Does one action that waited for the address to be confirmed.
	 *
	 * @param array<string, mixed> $action   Waiting action.
	 * @param int                  $guest_id Guest who confirmed.
	 * @return array{message: string, url: string, label: string}
	 */
	private static function complete( array $action, int $guest_id ): array {
		if ( self::PENDING_VOTE === ( $action['type'] ?? '' ) ) {
			$request_id = (int) ( $action['request'] ?? 0 );
			$result     = VoteService::cast( $request_id );
			$request    = get_post( $request_id );
			$public     = $request && 'publish' === $request->post_status && '' === $request->post_password;
			$title      = $public ? Mailer::plain( $request->post_title ) : '';

			if ( is_wp_error( $result ) ) {
				return [
					'message' => '' !== $title
						/* translators: 1: request title, 2: why the vote was refused. */
						? sprintf( __( 'Your vote for "%1$s" could not be added. %2$s', 'feature-request-system' ), $title, $result->get_error_message() )
						: $result->get_error_message(),
					'url'     => $public ? (string) get_permalink( $request ) : '',
					'label'   => __( 'Go to the request', 'feature-request-system' ),
				];
			}

			return [
				/* translators: %s: request title. */
				'message' => sprintf( __( 'Your vote for "%s" was added.', 'feature-request-system' ), $title ),
				'url'     => (string) get_permalink( $request_id ),
				'label'   => __( 'Go to the request', 'feature-request-system' ),
			];
		}

		$board_id   = (int) ( $action['board'] ?? 0 );
		$submission = is_array( $action['submission'] ?? null ) ? $action['submission'] : [];
		$title      = (string) ( $submission['title'] ?? '' );
		$result     = SubmissionService::complete( $board_id, $submission, ! empty( $action['flagged'] ), $guest_id );

		if ( is_wp_error( $result ) ) {
			return [
				/* translators: 1: title of the feature request, 2: why it was refused. */
				'message' => sprintf( __( 'Your feature request "%1$s" could not be sent. %2$s', 'feature-request-system' ), $title, $result->get_error_message() ),
				'url'     => BoardSettings::page_url( $board_id ),
				'label'   => __( 'Go to the board', 'feature-request-system' ),
			];
		}

		$published = 'publish' === $result['status'];

		return [
			'message' => $published
				/* translators: %s: title of the feature request. */
				? sprintf( __( 'Your feature request "%s" is on the board.', 'feature-request-system' ), $title )
				/* translators: %s: title of the feature request. */
				: sprintf( __( 'Your feature request "%s" was sent. It will appear on the board once it is approved.', 'feature-request-system' ), $title ),
			'url'     => $published ? $result['url'] : BoardSettings::page_url( $board_id ),
			'label'   => $published ? __( 'Go to your request', 'feature-request-system' ) : __( 'Go to the board', 'feature-request-system' ),
		];
	}

	/**
	 * Adds an action to those that wait. The same vote is kept once, and the oldest
	 * actions make way for new ones beyond the limit.
	 *
	 * @param list<array<string, mixed>> $pending Actions that wait, oldest first.
	 * @param array<string, mixed>       $action  New action.
	 * @return list<array<string, mixed>>
	 */
	private static function add_pending( array $pending, array $action ): array {
		$pending   = array_values(
			array_filter(
				$pending,
				static function ( array $waiting ) use ( $action ): bool {
					return self::PENDING_VOTE !== $action['type'] || $waiting !== $action;
				}
			)
		);
		$pending[] = $action;

		return array_slice( $pending, -self::MAX_PENDING );
	}

	/**
	 * Makes the current visitor a confirmed guest, now and for a year: with a first-party
	 * cookie that scripts cannot read.
	 *
	 * @param array<string, mixed> $guest Guest.
	 * @phpstan-param Guest $guest
	 */
	private static function remember( array $guest ): void {
		$value = $guest['id'] . '.' . self::signature( $guest );

		// The rest of this request treats the visitor as the guest.
		$_COOKIE[ self::COOKIE ] = $value;

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				[
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH,
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
	}

	/**
	 * Signs a guest's cookie with a secret only this site knows.
	 *
	 * The signature covers the address and when the row was added, so a cookie stops
	 * working when its guest is erased, also if the database gives the ID to a new row.
	 *
	 * @param array<string, mixed> $guest Guest.
	 * @phpstan-param Guest $guest
	 */
	private static function signature( array $guest ): string {
		return hash_hmac( 'sha256', 'guest|' . $guest['id'] . '|' . $guest['email'] . '|' . $guest['created_at'], wp_salt( 'auth' ) );
	}

	/**
	 * Hashes a link's token for storage.
	 *
	 * @param string $token Token.
	 */
	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Cleans a name for storage.
	 *
	 * @param string $name Name as given.
	 */
	private static function name( string $name ): string {
		return mb_substr( sanitize_text_field( $name ), 0, SubmissionService::MAX_NAME );
	}
}
