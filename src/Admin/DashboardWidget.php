<?php
/**
 * Feature Requests widget on the dashboard.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Voting\VoteRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Shows moderators, on the dashboard, the requests that wait longest for approval and the
 * requests that got the most votes in the last seven days.
 */
final class DashboardWidget {

	/**
	 * ID of the widget, also the element ID of its box.
	 */
	public const ID = 'mwtfrs-dashboard';

	/**
	 * Requests listed in each part.
	 */
	public const SHOWN = 5;

	/**
	 * How far back "this week" reaches.
	 */
	private const WEEK = 7 * DAY_IN_SECONDS;

	/**
	 * Adds the widget to the dashboard.
	 */
	public static function add_hooks(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'add' ] );
	}

	/**
	 * Registers the widget for moderators.
	 */
	public static function add(): void {
		if ( current_user_can( Capabilities::MODERATE ) ) {
			wp_add_dashboard_widget( self::ID, __( 'Feature Requests', 'feature-request-system' ), [ self::class, 'render' ] );
		}
	}

	/**
	 * Prints the widget.
	 */
	public static function render(): void {
		$waiting = (int) wp_count_posts( RequestPostType::NAME )->pending;

		echo '<div class="activity-block"><h3>' . esc_html__( 'Waiting for approval', 'feature-request-system' ) . '</h3>';
		if ( ! $waiting ) {
			echo '<p>' . esc_html__( 'No request is waiting for approval.', 'feature-request-system' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( self::oldest_waiting() as $request ) {
				printf(
					'<li>%1$s, %2$s</li>',
					self::link( $request ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in link().
					/* translators: %s: how long ago, such as "3 days". */
					esc_html( sprintf( __( 'suggested %s ago', 'feature-request-system' ), human_time_diff( (int) get_post_timestamp( $request ) ) ) )
				);
			}
			echo '</ul>';

			if ( $waiting > self::SHOWN ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( add_query_arg( 'post_status', 'pending', admin_url( 'edit.php?post_type=' . RequestPostType::NAME ) ) ),
					/* translators: %s: number of requests. */
					esc_html( sprintf( _n( 'See the %s request waiting', 'See all %s requests waiting', $waiting, 'feature-request-system' ), number_format_i18n( $waiting ) ) )
				);
			}
		}
		echo '</div>';

		$top = VoteRepository::top_since( gmdate( 'Y-m-d H:i:s', time() - self::WEEK ), self::SHOWN );

		echo '<div class="activity-block"><h3>' . esc_html__( 'Top requests this week', 'feature-request-system' ) . '</h3>';
		if ( ! $top ) {
			echo '<p>' . esc_html__( 'No votes in the last seven days.', 'feature-request-system' ) . '</p>';
		} else {
			_prime_post_caches( array_keys( $top ), false, false );

			echo '<ol>';
			foreach ( $top as $request_id => $votes ) {
				$request = get_post( $request_id );
				if ( ! $request instanceof WP_Post ) {
					continue;
				}

				printf(
					'<li>%1$s, %2$s</li>',
					self::link( $request ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in link().
					/* translators: %s: number of votes. */
					esc_html( sprintf( _n( '%s vote in the last seven days', '%s votes in the last seven days', $votes, 'feature-request-system' ), number_format_i18n( $votes ) ) )
				);
			}
			echo '</ol>';
		}
		echo '</div>';
	}

	/**
	 * Returns the requests that wait longest for approval.
	 *
	 * @return WP_Post[]
	 */
	private static function oldest_waiting(): array {
		return get_posts(
			[
				'post_type'      => RequestPostType::NAME,
				'post_status'    => 'pending',
				'posts_per_page' => self::SHOWN,
				'orderby'        => [
					'date' => 'ASC',
					'ID'   => 'ASC',
				],
			]
		);
	}

	/**
	 * Returns a request's title, linked to its edit screen.
	 *
	 * @param WP_Post $request Request.
	 */
	private static function link( WP_Post $request ): string {
		$title = get_the_title( $request );

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( (string) get_edit_post_link( $request->ID ) ),
			esc_html( '' !== $title ? $title : __( '(no title)', 'feature-request-system' ) )
		);
	}
}
