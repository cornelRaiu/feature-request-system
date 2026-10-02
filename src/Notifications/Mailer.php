<?php
/**
 * Emails to followers.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use FeatureRequestSystem\Frontend\Template;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one plain text email to one follower of a request, in that user's language,
 * with a link that stops the emails.
 *
 * The text comes from a template in templates/emails/, which a theme can override.
 *
 * @phpstan-import-type Subscription from SubscriptionRepository
 */
final class Mailer {

	/**
	 * Filter to change an email to a follower, or to stop it by returning false. Receives
	 * the email (to, subject, message and headers), the template name, the request and
	 * the user the email goes to.
	 */
	public const FILTER = 'mwtfrs_follower_email';

	/**
	 * Sends an email about a request to one of its followers.
	 *
	 * @param array<string, int|string> $subscription The follower's subscription, from SubscriptionRepository.
	 * @param string                    $template     Template name in templates/emails/, such as "status-changed".
	 * @param callable                  $compose      Returns the email's subject and the template's own values. It
	 *                                                receives the request, and runs in the follower's language.
	 * @phpstan-param Subscription $subscription
	 * @phpstan-param callable(WP_Post): array{subject: string, args: array<string, mixed>} $compose
	 * @return bool Whether the email was handed over for sending.
	 */
	public static function send( array $subscription, string $template, callable $compose ): bool {
		$user    = get_userdata( $subscription['user_id'] );
		$request = get_post( $subscription['request_id'] );
		if ( ! $user || ! $request || ! is_email( $user->user_email ) ) {
			return false;
		}

		$switched = switch_to_user_locale( $user->ID );
		$parts    = $compose( $request );
		$stop_url = Unsubscribe::url( $subscription['token'] );
		// What a template gets is plain text. WordPress stores names and titles with some characters as entities.
		$args = array_merge(
			$parts['args'],
			[
				'name'            => self::plain( $user->display_name ),
				'site'            => self::plain( (string) get_bloginfo( 'name' ) ),
				'title'           => self::plain( $request->post_title ),
				'url'             => (string) get_permalink( $request ),
				'reason'          => self::reason( $subscription['source'] ),
				'unsubscribe_url' => $stop_url,
			]
		);

		$email = [
			'to'      => $user->user_email,
			'subject' => $parts['subject'],
			// The template escapes its values as any template does. An email is not HTML, so that is undone here.
			'message' => self::plain( Template::render( 'emails/' . $template, $args ) ),
			'headers' => [
				// Mail programs show their own unsubscribe button for these, and post to the link when it is used.
				// WordPress encodes long headers when it sends with PHP's mail(), which not every mail program
				// reads, so the button is only certain on sites that send through SMTP or a mail service.
				'List-Unsubscribe: <' . $stop_url . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			],
		];
		$email = apply_filters( self::FILTER, $email, $template, $request, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		if ( $switched ) {
			restore_previous_locale();
		}

		return is_array( $email ) && wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * Turns stored text, which may contain tags or entities, into plain text for an email.
	 *
	 * @param string $html Text that may contain tags or entities.
	 */
	public static function plain( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Says why the user gets the email.
	 *
	 * @param string $source Why the user follows the request, one of SubscriptionRepository's SOURCE constants.
	 */
	private static function reason( string $source ): string {
		switch ( $source ) {
			case SubscriptionRepository::SOURCE_VOTE:
				return __( 'You get this email because you voted for this request.', 'feature-request-system' );
			case SubscriptionRepository::SOURCE_AUTHOR:
				return __( 'You get this email because you suggested this request.', 'feature-request-system' );
			default:
				return __( 'You get this email because you follow this request.', 'feature-request-system' );
		}
	}
}
