<?php
/**
 * The link that stops the emails.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the link at the end of every email to a follower. It works without logging in:
 * the secret token in the link stands for one user following one request.
 *
 * Opening the link only asks. The user stops following when the page's button is pressed,
 * or when a mail program posts to the link for its own unsubscribe button. Programs that
 * open every link in an email to scan it therefore cannot unsubscribe anybody.
 *
 * @phpstan-import-type Subscription from SubscriptionRepository
 */
final class Unsubscribe {

	/**
	 * URL parameter that holds the token, on the site's home URL.
	 */
	public const PARAM = 'mwtfrs_unsubscribe';

	/**
	 * Form field that asks to stop following every request, not just the link's.
	 */
	private const FIELD_ALL = 'all';

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
	 * Returns the link for a subscription.
	 *
	 * The link is on the home URL, not in wp-admin or the REST API, because sites often
	 * close those to visitors who are not logged in.
	 *
	 * @param string $token The subscription's token.
	 */
	public static function url( string $token ): string {
		return add_query_arg( self::PARAM, $token, home_url( '/' ) );
	}

	/**
	 * Answers the link with a page, and ends the request.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- The secret token in the link is the proof. Mail programs post to the link without cookies, so it cannot carry a nonce.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}

		$token  = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );
		$posted = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$page   = self::process( $token, $posted, $posted && ! empty( $_POST[ self::FIELD_ALL ] ) );
		// phpcs:enable WordPress.Security.NonceVerification

		// The address of this page holds the secret. Without this, the page the visitor goes
		// to next would get it as the referrer, and so would every script on that page.
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		wp_die( $page['html'], esc_html( $page['title'] ), [ 'response' => $page['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- process() escapes every value.
	}

	/**
	 * Does what the link was asked to do and returns the page to show.
	 *
	 * @param string $token  Token from the link.
	 * @param bool   $posted Whether the link was posted to. Otherwise it was only opened.
	 * @param bool   $all    Whether the post asks to stop following every request.
	 * @return array{status: int, title: string, html: string}
	 */
	public static function process( string $token, bool $posted, bool $all ): array {
		$subscription = SubscriptionRepository::find_by_token( $token );
		if ( ! $subscription ) {
			return self::page(
				404,
				__( 'This link no longer works', 'feature-request-system' ),
				[
					self::paragraph( __( 'You may have stopped following this request already.', 'feature-request-system' ) ),
					self::home_link(),
				]
			);
		}

		// Only a request that anyone can see is named and linked.
		$request = get_post( $subscription['request_id'] );
		$request = $request && 'publish' === $request->post_status ? $request : null;

		if ( ! $posted ) {
			return self::ask( $subscription, $request );
		}

		if ( $all ) {
			SubscriptionRepository::remove_user( $subscription['user_id'] );
			Subscriptions::set_automatic( $subscription['user_id'], false );

			return self::page(
				200,
				__( 'You no longer follow any request', 'feature-request-system' ),
				[
					self::paragraph( __( 'We will not email you about feature requests again. Voting for a request or suggesting one no longer makes you follow it.', 'feature-request-system' ) ),
					'<p>' . wp_kses(
						sprintf(
							/* translators: %s: URL of the profile screen. */
							__( 'You can still follow a request with the button on its page, and change this choice in <a href="%s">your profile</a>.', 'feature-request-system' ),
							esc_url( admin_url( 'profile.php' ) )
						),
						[ 'a' => [ 'href' => [] ] ]
					) . '</p>',
					self::home_link(),
				]
			);
		}

		SubscriptionRepository::remove( $subscription['request_id'], $subscription['user_id'] );

		return self::page(
			200,
			__( 'You no longer follow this request', 'feature-request-system' ),
			array_merge(
				[
					self::paragraph(
						$request
							/* translators: %s: request title. */
							? sprintf( __( 'We will not email you about "%s" again.', 'feature-request-system' ), self::title( $request ) )
							: __( 'We will not email you about this request again.', 'feature-request-system' )
					),
				],
				self::offer_all( $subscription['user_id'], true ),
				$request ? [ self::link( (string) get_permalink( $request ), __( 'Go to the request', 'feature-request-system' ) ) ] : []
			)
		);
	}

	/**
	 * Builds the page that asks whether to stop following.
	 *
	 * @param array<string, int|string> $subscription Subscription of the link.
	 * @param WP_Post|null              $request      The request, when anyone can see it.
	 * @phpstan-param Subscription $subscription
	 * @return array{status: int, title: string, html: string}
	 */
	private static function ask( array $subscription, ?WP_Post $request ): array {
		return self::page(
			200,
			__( 'Stop following this request?', 'feature-request-system' ),
			array_merge(
				[
					self::paragraph(
						$request
							/* translators: %s: request title. */
							? sprintf( __( 'You will get no more emails about "%s".', 'feature-request-system' ), self::title( $request ) )
							: __( 'You will get no more emails about this request.', 'feature-request-system' )
					),
					self::form( $subscription['token'], false, __( 'Stop following', 'feature-request-system' ) ),
				],
				self::offer_all( $subscription['user_id'], false ),
				$request ? [ self::link( (string) get_permalink( $request ), __( 'Keep following and go to the request', 'feature-request-system' ) ) ] : []
			)
		);
	}

	/**
	 * Offers to stop every email, for a user who still follows requests.
	 *
	 * @param int  $user_id   User ID.
	 * @param bool $unfollowed Whether the user just stopped following the link's request.
	 * @return string[] HTML parts, or none when the user follows nothing.
	 */
	private static function offer_all( int $user_id, bool $unfollowed ): array {
		$count = SubscriptionRepository::count_of_user( $user_id );
		$any   = SubscriptionRepository::of_user( $user_id, 1 );
		if ( ! $any ) {
			return [];
		}

		$follows = $unfollowed
			/* translators: %s: number of requests. */
			? _n( 'You still follow %s other request on this site.', 'You still follow %s other requests on this site.', $count, 'feature-request-system' )
			/* translators: %s: number of requests. */
			: _n( 'You follow %s request on this site.', 'You follow %s requests on this site.', $count, 'feature-request-system' );

		return [
			self::paragraph( sprintf( $follows, number_format_i18n( $count ) ) . ' ' . __( 'You can also stop all of these emails. Voting for a request will then no longer make you follow it.', 'feature-request-system' ) ),
			// Any of the user's links proves who they are, also after the one in the email was used.
			self::form( $any[0]['token'], true, __( 'Stop all emails', 'feature-request-system' ) ),
		];
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

	/**
	 * Builds a form with one button that posts to a subscription's link.
	 *
	 * @param string $token Token of the subscription.
	 * @param bool   $all   Whether the button stops following every request.
	 * @param string $label Button text.
	 */
	private static function form( string $token, bool $all, string $label ): string {
		return sprintf(
			'<form method="post" action="%1$s">%2$s<p><button type="submit" class="button button-large">%3$s</button></p></form>',
			esc_url( self::url( $token ) ),
			$all ? '<input type="hidden" name="' . esc_attr( self::FIELD_ALL ) . '" value="1">' : '',
			esc_html( $label )
		);
	}

	/**
	 * Returns a request's title as plain text.
	 *
	 * @param WP_Post $request Request.
	 */
	private static function title( WP_Post $request ): string {
		return Mailer::plain( $request->post_title );
	}
}
