<?php
/**
 * Dashboard widget tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\DashboardWidget;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Install\Schema;

/**
 * The Feature Requests widget on the dashboard: who gets it, the requests that wait for
 * approval and the week's top requests.
 */
final class DashboardWidgetTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp_meta_boxes'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_only_moderators_get_the_widget(): void {
		$this->assertSame( [], $this->widgets_for( 'author' ) );
		$this->assertSame( [], $this->widgets_for( 'subscriber' ) );
		$this->assertSame( [ DashboardWidget::ID => 'Feature Requests' ], $this->widgets_for( 'editor' ) );
		$this->assertSame( [ DashboardWidget::ID => 'Feature Requests' ], $this->widgets_for( 'administrator' ) );
		$this->assertContains( '#' . DashboardWidget::ID . ' li a { text-decoration: underline; }', (array) wp_styles()->get_data( 'dashboard', 'after' ), 'Its links stand out from the text beside them.' );
	}

	public function test_the_widget_lists_the_five_requests_that_wait_longest(): void {
		$days    = [ 3, 9, 1, 7, 5, 12, 2 ];
		$waiting = [];
		foreach ( $days as $index => $days_ago ) {
			$waiting[ $days_ago ] = $this->create_request(
				[
					'post_title'    => 'Waiting ' . $days_ago . ' days',
					'post_status'   => 'pending',
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS - $index ),
				]
			);
		}
		$this->create_request( [ 'post_title' => 'Already published' ] );
		$this->create_request(
			[
				'post_title'  => 'A draft',
				'post_status' => 'draft',
			]
		);

		$html = $this->widget();

		preg_match_all( '#<li><a href="([^"]+)">([^<]+)</a>, suggested ([^<]+) ago</li>#', $this->part( $html, 0 ), $items );
		$this->assertSame( [ 'Waiting 12 days', 'Waiting 9 days', 'Waiting 7 days', 'Waiting 5 days', 'Waiting 3 days' ], $items[2], 'The oldest first.' );
		$this->assertSame( [ '2 weeks', '1 week', '1 week', '5 days', '3 days' ], $items[3] );
		$this->assertSame( esc_url( get_edit_post_link( $waiting[12] ) ), $items[1][0] );
		$this->assertStringContainsString( '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . RequestPostType::NAME . '&post_status=pending' ) ) . '">See all 7 requests waiting</a>', $html );
	}

	public function test_a_short_queue_has_no_link_to_the_rest_and_an_empty_one_says_so(): void {
		$this->create_request( [ 'post_status' => 'pending' ] );
		$this->assertStringNotContainsString( 'See all', $this->widget() );

		$this->assertStringContainsString( '<p>No request is waiting for approval.</p>', $this->widget_without_waiting() );
	}

	public function test_the_top_requests_have_the_most_votes_of_the_last_seven_days(): void {
		$steady  = $this->create_request( [ 'post_title' => 'Steady' ] );
		$old_hit = $this->create_request( [ 'post_title' => 'Popular long ago' ] );
		$new_hit = $this->create_request( [ 'post_title' => 'New favourite' ] );
		$pending = $this->create_request(
			[
				'post_title'  => 'Waiting with votes',
				'post_status' => 'pending',
			]
		);
		$this->votes( $steady, 2, 1 );
		$this->votes( $steady, 1, 6 );
		$this->votes( $old_hit, 9, 8 );
		$this->votes( $old_hit, 1, 2 );
		$this->votes( $new_hit, 4, 0 );
		$this->votes( $pending, 5, 0 );

		preg_match_all( '#<li><a href="[^"]+">([^<]+)</a>, ([^<]+)</li>#', $this->part( $this->widget(), 1 ), $items );

		$this->assertSame( [ 'New favourite', 'Steady', 'Popular long ago' ], $items[1] );
		$this->assertSame( [ '4 votes in the last seven days', '3 votes in the last seven days', '1 vote in the last seven days' ], $items[2] );
	}

	public function test_the_top_requests_stop_at_five_and_a_quiet_week_says_so(): void {
		$this->assertStringContainsString( '<p>No votes in the last seven days.</p>', $this->widget() );

		for ( $i = 1; $i <= 7; $i++ ) {
			$this->votes( $this->create_request( [ 'post_title' => 'Request ' . $i ] ), $i, 0 );
		}

		preg_match_all( '#<li><a href="[^"]+">([^<]+)</a>#', $this->part( $this->widget(), 1 ), $items );
		$this->assertSame( [ 'Request 7', 'Request 6', 'Request 5', 'Request 4', 'Request 3' ], $items[1] );
	}

	/**
	 * Builds the dashboard for a new user with a role and returns the plugin's widgets on it.
	 *
	 * @param string $role Role name.
	 * @return array<string, string> Titles by widget ID.
	 */
	private function widgets_for( string $role ): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
		set_current_screen( 'dashboard' );
		$GLOBALS['wp_meta_boxes'] = [];

		do_action( 'wp_dashboard_setup' );

		$widgets = [];
		foreach ( $GLOBALS['wp_meta_boxes']['dashboard'] ?? [] as $contexts ) {
			foreach ( $contexts as $boxes ) {
				foreach ( $boxes as $id => $box ) {
					if ( DashboardWidget::ID === $id ) {
						$widgets[ $id ] = $box['title'];
					}
				}
			}
		}

		return $widgets;
	}

	/**
	 * Returns the widget's content, as a moderator sees it.
	 */
	private function widget(): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		ob_start();
		DashboardWidget::render();

		return (string) ob_get_clean();
	}

	/**
	 * Returns the widget's content once no request waits for approval.
	 */
	private function widget_without_waiting(): string {
		foreach ( get_posts(
			[
				'post_type'   => RequestPostType::NAME,
				'post_status' => 'pending',
				'fields'      => 'ids',
			]
		) as $request ) {
			wp_publish_post( $request );
		}

		return $this->widget();
	}

	/**
	 * Returns one part of the widget: 0 for the waiting requests, 1 for the top requests.
	 *
	 * @param string $html  The widget's content.
	 * @param int    $index Part.
	 */
	private function part( string $html, int $index ): string {
		$parts = explode( '<div class="activity-block">', $html );
		$this->assertCount( 3, $parts, 'Two parts.' );

		return $parts[ $index + 1 ];
	}

	/**
	 * Adds votes from made-up visitors, cast some days ago.
	 *
	 * @param int $request_id Request ID.
	 * @param int $count      How many votes.
	 * @param int $days_ago   How many days ago they were cast.
	 */
	private function votes( int $request_id, int $count, int $days_ago ): void {
		global $wpdb;

		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				Schema::table( Schema::VOTES ),
				[
					'request_id' => $request_id,
					'voter_key'  => hash( 'sha256', wp_generate_password( 20, false ) ),
					'user_id'    => 0,
					// Within the day, so that seven days ago is not on the edge.
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS - HOUR_IN_SECONDS ),
				]
			);
		}
	}
}
