<?php
/**
 * Request details box on the edit screen.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Voting\VoteRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Shows moderators who voted for a request, how many users follow it and who submitted
 * it. A submitter's name and email are shown nowhere else.
 */
final class RequestDetailsBox {

	/**
	 * ID of the meta box.
	 */
	private const ID = 'mwtfrs-request-details';

	/**
	 * Most voters to list by name.
	 */
	private const VOTERS_SHOWN = 20;

	/**
	 * Adds the box to the request edit screen.
	 */
	public static function add_hooks(): void {
		add_action( 'add_meta_boxes_' . RequestPostType::NAME, [ self::class, 'add' ] );
	}

	/**
	 * Registers the box.
	 */
	public static function add(): void {
		add_meta_box( self::ID, __( 'Request details', 'feature-request-system' ), [ self::class, 'render' ], RequestPostType::NAME, 'side', 'high' );
	}

	/**
	 * Prints the box.
	 *
	 * @param WP_Post $post Request being edited.
	 */
	public static function render( WP_Post $post ): void {
		$voters = VoteRepository::voters( $post->ID, self::VOTERS_SHOWN );
		$votes  = $voters['user_votes'] + $voters['guest_votes'];

		printf(
			'<p><strong>%s</strong></p>',
			/* translators: %s: number of votes. */
			esc_html( sprintf( _n( '%s vote', '%s votes', $votes, 'feature-request-system' ), number_format_i18n( $votes ) ) )
		);

		if ( $voters['users'] ) {
			echo '<ul>';
			foreach ( $voters['users'] as $user_id ) {
				echo '<li>' . self::user( $user_id ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in user().
			}
			echo '</ul>';
		}

		$more = array_filter(
			[
				/* translators: %s: number of users. */
				$voters['user_votes'] > count( $voters['users'] ) ? sprintf( _n( '%s more user', '%s more users', $voters['user_votes'] - count( $voters['users'] ), 'feature-request-system' ), number_format_i18n( $voters['user_votes'] - count( $voters['users'] ) ) ) : '',
				/* translators: %s: number of visitors who voted without an account. */
				$voters['guest_votes'] ? sprintf( _n( '%s guest', '%s guests', $voters['guest_votes'], 'feature-request-system' ), number_format_i18n( $voters['guest_votes'] ) ) : '',
			]
		);
		if ( $more ) {
			echo '<p>' . esc_html( implode( ', ', $more ) ) . '</p>';
		}

		$followers = SubscriptionRepository::count( $post->ID );
		if ( $followers ) {
			printf(
				'<p>%s</p>',
				/* translators: %s: number of users who follow the request. */
				esc_html( sprintf( _n( '%s follower', '%s followers', $followers, 'feature-request-system' ), number_format_i18n( $followers ) ) )
			);
		}

		$submitter = self::submitter( $post->ID );
		if ( '' !== $submitter ) {
			/* translators: %s: name of the person who submitted the request, with their email if they gave one. */
			printf( '<p>' . esc_html__( 'Submitted by %s', 'feature-request-system' ) . '</p>', $submitter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in submitter().
		}

		if ( get_post_meta( $post->ID, RequestPostType::META_FLAGGED_AS_SPAM, true ) ) {
			echo '<p>' . esc_html__( 'A spam check flagged this request, so it was held for review.', 'feature-request-system' ) . '</p>';
		}
	}

	/**
	 * Returns a user's name, linked to their profile for those who may edit it.
	 *
	 * @param int $user_id User ID.
	 */
	private static function user( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return esc_html__( 'A user who has since been deleted', 'feature-request-system' );
		}

		$link = get_edit_user_link( $user_id );

		return $link
			? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $user->display_name ) )
			: esc_html( $user->display_name );
	}

	/**
	 * Returns who submitted a request from the front end, or '' for a request written in the admin.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function submitter( int $request_id ): string {
		$user_id = (int) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_ID, true );
		if ( $user_id ) {
			return self::user( $user_id );
		}

		$name  = (string) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_NAME, true );
		$email = sanitize_email( (string) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_EMAIL, true ) );
		$parts = array_filter(
			[
				esc_html( $name ),
				$email ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email ), esc_html( $email ) ) : '',
			]
		);

		return implode( ', ', $parts );
	}
}
