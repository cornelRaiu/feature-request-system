<?php
/**
 * Email to moderators about new requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\SubmissionService;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the site's moderators about each submitted request, with a link to review it.
 */
final class NewRequestEmail {

	/**
	 * Sends the email whenever a request is submitted.
	 */
	public static function add_hooks(): void {
		add_action( SubmissionService::ACTION_SUBMITTED, [ self::class, 'send' ] );
	}

	/**
	 * Emails the moderators about a request, unless the site turned these emails off.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function send( int $request_id ): void {
		$request    = get_post( $request_id );
		$recipients = self::recipients();
		if ( ! $request || ! $recipients || ! Settings::get( Settings::NOTIFY_NEW_REQUESTS ) ) {
			return;
		}

		$site    = Mailer::plain( (string) get_bloginfo( 'name' ) );
		$title   = Mailer::plain( $request->post_title );
		$pending = 'pending' === $request->post_status;
		$board   = RequestTerms::term_of( $request_id, BoardTaxonomy::NAME );

		$subject = $pending
			/* translators: 1: site name, 2: request title. */
			? sprintf( __( '[%1$s] New feature request to approve: %2$s', 'feature-request-system' ), $site, $title )
			/* translators: 1: site name, 2: request title. */
			: sprintf( __( '[%1$s] New feature request: %2$s', 'feature-request-system' ), $site, $title );

		$lines = [
			/* translators: %s: request title. */
			sprintf( __( 'Title: %s', 'feature-request-system' ), $title ),
			/* translators: %s: board name. */
			sprintf( __( 'Board: %s', 'feature-request-system' ), $board ? Mailer::plain( $board->name ) : '-' ),
			/* translators: %s: name and email of the person who submitted the request. */
			sprintf( __( 'Submitted by: %s', 'feature-request-system' ), self::submitter( $request_id ) ),
		];

		if ( '' !== $request->post_content ) {
			$lines[] = '';
			$lines[] = Mailer::plain( $request->post_content );
		}

		$lines[] = '';
		if ( get_post_meta( $request_id, RequestPostType::META_FLAGGED_AS_SPAM, true ) ) {
			$lines[] = __( 'A spam check flagged this request, so it is waiting for review.', 'feature-request-system' );
		}
		$lines[] = $pending
			? __( 'It is waiting for your approval. Review it here:', 'feature-request-system' )
			: __( 'It is already on the board. Edit it here:', 'feature-request-system' );
		$lines[] = admin_url( 'post.php?post=' . $request_id . '&action=edit' );

		wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}

	/**
	 * The configured addresses, or the site admin email.
	 *
	 * @return string[]
	 */
	private static function recipients(): array {
		$configured = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( Settings::NOTIFY_EMAILS ) ) ), 'is_email' );
		if ( $configured ) {
			return array_values( $configured );
		}

		$admin = (string) get_option( 'admin_email' );

		return is_email( $admin ) ? [ $admin ] : [];
	}

	/**
	 * Describes who submitted a request, for the moderators' eyes only.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function submitter( int $request_id ): string {
		$user_id = (int) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_ID, true );
		if ( $user_id ) {
			$user = get_userdata( $user_id );

			return $user ? sprintf( '%1$s <%2$s>', Mailer::plain( $user->display_name ), $user->user_email ) : __( 'a user who has since been deleted', 'feature-request-system' );
		}

		$name  = (string) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_NAME, true );
		$email = (string) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_EMAIL, true );
		if ( '' === $email ) {
			return '' !== $name ? $name : __( 'a visitor who left no name', 'feature-request-system' );
		}

		return '' !== $name ? sprintf( '%1$s <%2$s>', $name, $email ) : $email;
	}
}
