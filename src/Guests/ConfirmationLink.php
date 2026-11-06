<?php
/**
 * The link that confirms a guest's email.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Guests;

use FeatureRequestSystem\Frontend\Template;
use FeatureRequestSystem\Notifications\Mailer;
use FeatureRequestSystem\Security\RequestOrigin;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the link that confirms a visitor's address, and serves the page it opens.
 *
 * Opening the link only asks. The address is confirmed when the page's button is
 * pressed, so programs that open every link in an email to scan it confirm nobody.
 *
 * The link is on the home URL, not in wp-admin or the REST API, because sites often
 * close those to visitors who are not logged in.
 *
 * @phpstan-import-type Guest from GuestRepository
 */
final class ConfirmationLink {

	/**
	 * URL parameter that holds the token, on the site's home URL.
	 */
	public const PARAM = 'mwtfrs_confirm';

	/**
	 * Filter to change the email with the link, or to stop it by returning false. Receives
	 * the email (to, subject, message and headers) and what waits for the confirmation.
	 */
	public const FILTER = 'mwtfrs_confirmation_email';

	/**
	 * Priority on init: after the request post type is registered, so requests have their links.
	 */
	private const PRIORITY = 30;

	/**
	 * Adds the hook that answers the link.
	 */
	public static function add_hooks(): void {
		add_action( 'init', [ self::class, 'handle' ], self::PRIORITY );
	}

	/**
	 * Returns the link for a token.
	 *
	 * @param string $token Token of the link.
	 */
	public static function url( string $token ): string {
		return add_query_arg( self::PARAM, $token, home_url( '/' ) );
	}

	/**
	 * Emails the link to an address, in the site's language.
	 *
	 * @param string                     $email   Address to confirm.
	 * @param string                     $token   Token of the link.
	 * @param list<array<string, mixed>> $pending What waits for the confirmation.
	 * @return bool Whether the email was handed over for sending.
	 */
	public static function send( string $email, string $token, array $pending ): bool {
		$site  = Mailer::plain( (string) get_bloginfo( 'name' ) );
		$email = [
			'to'      => $email,
			/* translators: %s: site name. */
			'subject' => sprintf( __( '[%s] Confirm your email address', 'feature-request-system' ), $site ),
			'message' => Mailer::plain(
				Template::render(
					'emails/confirm-email',
					[
						'site'    => $site,
						'url'     => self::url( $token ),
						'hours'   => (int) ( Guests::LINK_LIFETIME / HOUR_IN_SECONDS ),
						'actions' => array_map(
							static function ( array $action ): string {
								return Guests::describe( $action, false );
							},
							$pending
						),
					]
				)
			),
			'headers' => [],
		];
		$email = apply_filters( self::FILTER, $email, $pending ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		return is_array( $email ) && wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * Answers the link with a page, and ends the request.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- The secret token in the link is the proof. The visitor opens it from their mail program, so the page cannot rely on a nonce.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}

		$token  = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );
		$posted = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// phpcs:enable WordPress.Security.NonceVerification
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		// The page sets a cookie, so no cache may keep it. Its address holds the secret, which
		// the page the visitor goes to next, and the scripts on it, must not get as the referrer:
		// they get the site's address only. Not no referrer at all, because a browser then sends
		// the button's post with an Origin of "null", and the post must show it comes from here.
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: strict-origin' );
		}

		$page = self::process( $token, $posted && RequestOrigin::is_same_site( $origin, $referer ) );

		wp_die( $page['html'], esc_html( $page['title'] ), [ 'response' => $page['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- process() escapes every value.
	}

	/**
	 * Does what the link was asked to do and returns the page to show.
	 *
	 * @param string $token  Token from the link.
	 * @param bool   $posted Whether the page's button was pressed, on a page of this site. Otherwise the link was only opened.
	 * @return array{status: int, title: string, html: string}
	 */
	public static function process( string $token, bool $posted ): array {
		$guest = Guests::by_token( $token );
		if ( ! $guest ) {
			return self::page(
				404,
				__( 'This link no longer works', 'feature-request-system' ),
				[
					self::paragraph( __( 'It may have been used already. To get a new link, vote or suggest the feature again.', 'feature-request-system' ) ),
					self::home_link(),
				]
			);
		}

		if ( ! Guests::link_works( $guest ) ) {
			return self::page(
				410,
				__( 'This link has expired', 'feature-request-system' ),
				[
					/* translators: %d: number of hours. */
					self::paragraph( sprintf( __( 'A link works for %d hours. To get a new one, vote or suggest the feature again.', 'feature-request-system' ), (int) ( Guests::LINK_LIFETIME / HOUR_IN_SECONDS ) ) ),
					self::home_link(),
				]
			);
		}

		if ( ! $posted ) {
			return self::ask( $guest, $token );
		}

		$done = Guests::confirm( $guest, $token );
		if ( null === $done ) {
			return self::process( '', false );
		}

		$parts = [ self::paragraph( __( 'Thank you. This browser now knows it is you, so you can vote and suggest features here without another link.', 'feature-request-system' ) ) ];
		foreach ( $done as $result ) {
			$parts[] = self::paragraph( $result['message'] );
			if ( '' !== $result['url'] ) {
				$parts[] = self::link( $result['url'], $result['label'] );
			}
		}
		if ( ! $done ) {
			$parts[] = self::home_link();
		}

		return self::page( 200, __( 'Your email address is confirmed', 'feature-request-system' ), $parts );
	}

	/**
	 * Builds the page that asks to confirm the address.
	 *
	 * @param array<string, mixed> $guest Guest of the link.
	 * @param string               $token Token of the link.
	 * @phpstan-param Guest $guest
	 * @return array{status: int, title: string, html: string}
	 */
	private static function ask( array $guest, string $token ): array {
		$parts = [
			/* translators: %s: email address. */
			self::paragraph( sprintf( __( 'Press the button to confirm that %s is your email address.', 'feature-request-system' ), $guest['email'] ) ),
		];

		if ( $guest['pending'] ) {
			$parts[] = self::paragraph( __( 'Then:', 'feature-request-system' ) );
			$parts[] = '<ul>' . implode(
				'',
				array_map(
					static function ( array $action ): string {
						return '<li>' . esc_html( Guests::describe( $action, true ) ) . '</li>';
					},
					$guest['pending']
				)
			) . '</ul>';
		}

		$parts[] = sprintf(
			'<form method="post" action="%1$s"><p><button type="submit" class="button button-large">%2$s</button></p></form>',
			esc_url( self::url( $token ) ),
			esc_html__( 'Confirm my email address', 'feature-request-system' )
		);
		$parts[] = self::paragraph( __( 'If you did not ask for this, close this page. Nothing happens unless the button is pressed.', 'feature-request-system' ) );

		return self::page( 200, __( 'Confirm your email address', 'feature-request-system' ), $parts );
	}

	/**
	 * Builds a page for wp_die().
	 *
	 * @param int      $status HTTP status.
	 * @param string   $title  Title, also shown as the page's heading.
	 * @param string[] $parts  Escaped HTML parts below the heading.
	 * @return array{status: int, title: string, html: string}
	 */
	private static function page( int $status, string $title, array $parts ): array {
		return [
			'status' => $status,
			'title'  => $title,
			'html'   => '<h1>' . esc_html( $title ) . '</h1>' . implode( '', $parts ),
		];
	}

	/**
	 * Builds a paragraph of text.
	 *
	 * @param string $text Text.
	 */
	private static function paragraph( string $text ): string {
		return '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Builds a paragraph with one link. The link does not pass on the address of this page, which holds the secret.
	 *
	 * @param string $url   Where the link goes.
	 * @param string $label Link text.
	 */
	private static function link( string $url, string $label ): string {
		return sprintf( '<p><a href="%1$s" rel="noreferrer">%2$s</a></p>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Builds a paragraph with a link to the site's home page.
	 */
	private static function home_link(): string {
		/* translators: %s: site name. */
		return self::link( home_url( '/' ), sprintf( __( 'Go to %s', 'feature-request-system' ), Mailer::plain( (string) get_bloginfo( 'name' ) ) ) );
	}
}
