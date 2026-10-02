<?php
/**
 * Followed requests on the profile screen.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Lets users see the requests they follow, stop following any of them, and choose whether
 * voting for a request or suggesting one makes them follow it.
 *
 * The section is on the user's own profile, and on a user's edit screen for those who
 * may edit that user.
 */
final class ProfileFollows {

	/**
	 * Name of the checkboxes that stop following a request. Each sends a request ID.
	 */
	public const FIELD_UNFOLLOW = 'mwtfrs_unfollow';

	/**
	 * Most followed requests to list.
	 */
	private const LISTED = 100;

	/**
	 * Adds the section to both screens and saves it.
	 */
	public static function add_hooks(): void {
		add_action( 'show_user_profile', [ self::class, 'render' ] );
		add_action( 'edit_user_profile', [ self::class, 'render' ] );
		add_action( 'personal_options_update', [ self::class, 'save' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'save' ] );
	}

	/**
	 * Prints the section. It is left out on a site that does not email followers, unless
	 * the user still follows requests from before.
	 *
	 * @param WP_User $user User whose profile is shown.
	 */
	public static function render( WP_User $user ): void {
		$subscriptions = SubscriptionRepository::of_user( $user->ID, self::LISTED );
		$enabled       = Subscriptions::enabled();
		if ( ! $enabled && ! $subscriptions ) {
			return;
		}

		$own = get_current_user_id() === $user->ID;

		echo '<h2 id="mwtfrs-following">' . esc_html__( 'Feature requests', 'feature-request-system' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		if ( $enabled ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s" value="0"><label><input type="checkbox" name="%2$s" value="1"%3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
				esc_html__( 'Following', 'feature-request-system' ),
				esc_attr( Subscriptions::META_AUTO_FOLLOW ),
				checked( Subscriptions::wants_automatic( $user->ID ), true, false ),
				$own
					? esc_html__( 'Follow the requests I vote for or suggest', 'feature-request-system' )
					: esc_html__( 'Follow the requests this user votes for or suggests', 'feature-request-system' ),
				esc_html__( 'A follower of a request gets an email when its status changes.', 'feature-request-system' )
			);
		}

		echo '<tr><th scope="row">' . esc_html__( 'Followed requests', 'feature-request-system' ) . '</th><td>';
		self::render_list( $subscriptions, SubscriptionRepository::count_of_user( $user->ID ) );
		echo '</td></tr></table>';
	}

	/**
	 * Saves the section, when the profile form was submitted by someone who may edit the user.
	 *
	 * @param int $user_id User whose profile is saved.
	 */
	public static function save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}

		// The choice is only on the form while the site emails followers.
		if ( isset( $_POST[ Subscriptions::META_AUTO_FOLLOW ] ) ) {
			Subscriptions::set_automatic( $user_id, '1' === $_POST[ Subscriptions::META_AUTO_FOLLOW ] );
		}

		$unfollow = isset( $_POST[ self::FIELD_UNFOLLOW ] ) && is_array( $_POST[ self::FIELD_UNFOLLOW ] ) ? array_map( 'absint', $_POST[ self::FIELD_UNFOLLOW ] ) : [];
		foreach ( $unfollow as $request_id ) {
			SubscriptionRepository::remove( $request_id, $user_id );
		}
	}

	/**
	 * Prints the requests a user follows, each with a box to stop following it.
	 *
	 * @param array<int, array<string, int|string>> $subscriptions The user's newest subscriptions.
	 * @param int                                   $total         How many requests the user follows.
	 */
	private static function render_list( array $subscriptions, int $total ): void {
		if ( ! $subscriptions ) {
			echo '<p>' . esc_html__( 'None.', 'feature-request-system' ) . '</p>';
			return;
		}

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Followed requests', 'feature-request-system' ) . '</legend><ul style="margin:0">';

		foreach ( $subscriptions as $subscription ) {
			$request = get_post( (int) $subscription['request_id'] );
			if ( ! $request instanceof WP_Post || RequestPostType::NAME !== $request->post_type ) {
				continue;
			}

			$title = get_the_title( $request );

			printf(
				'<li>%1$s &nbsp; <label><input type="checkbox" name="%2$s[]" value="%3$d"> %4$s<span class="screen-reader-text"> %5$s</span></label></li>',
				// A request that is not public, such as one waiting for approval, has no page to link to.
				'publish' === $request->post_status
					? sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_permalink( $request ) ), esc_html( $title ) )
					: esc_html( $title ),
				esc_attr( self::FIELD_UNFOLLOW ),
				(int) $request->ID,
				esc_html__( 'Stop following', 'feature-request-system' ),
				esc_html( $title )
			);
		}

		echo '</ul>';

		if ( $total > count( $subscriptions ) ) {
			printf(
				'<p class="description">%s</p>',
				/* translators: %s: number of requests. */
				esc_html( sprintf( _n( 'And %s more.', 'And %s more.', $total - count( $subscriptions ), 'feature-request-system' ), number_format_i18n( $total - count( $subscriptions ) ) ) )
			);
		}

		echo '</fieldset>';
	}
}
