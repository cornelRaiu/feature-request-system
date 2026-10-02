<?php
/**
 * Notify switch tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use FeatureRequestSystem\Admin\NotifySwitch;
use FeatureRequestSystem\Admin\RequestList;
use FeatureRequestSystem\Admin\StatusQuickEdit;
use FeatureRequestSystem\Admin\TermSelectBox;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Notifications\StatusNotifier;
use FeatureRequestSystem\Settings;
use WP_REST_Request;

/**
 * A moderator can change a status without an email to the followers, from the edit
 * screen, Quick Edit and Bulk Edit.
 */
final class NotifySwitchTest extends TestCase {

	private const FIELD_HTML = '<input type="hidden" name="' . NotifySwitch::FIELD . '" value="0"><label><input type="checkbox" name="' . NotifySwitch::FIELD . '" value="1" checked> ';

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$_POST    = [];
		$_REQUEST = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	public function tear_down(): void {
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function test_the_status_box_has_the_switch_when_the_request_has_followers(): void {
		$request = $this->create_request();
		$this->assertStringNotContainsString( NotifySwitch::FIELD, $this->status_box( $request ), 'Nobody to email.' );

		$this->create_followers( $request, 1 );
		$this->assertStringContainsString( '<p>' . self::FIELD_HTML . 'Email 1 follower when the status changes</label></p>', $this->status_box( $request ) );

		$this->create_followers( $request, 2 );
		$html = $this->status_box( $request );
		$this->assertStringContainsString( self::FIELD_HTML . 'Email 3 followers when the status changes</label>', $html );
		$this->assertStringContainsString( '<p class="description">The emails go out 5 minutes after the last change, so a status picked by mistake can still be corrected.</p>', $html );

		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$this->assertStringNotContainsString( NotifySwitch::FIELD, $this->status_box( $request ), 'The site does not email followers.' );
	}

	public function test_quick_edit_and_bulk_edit_have_the_switch_while_the_site_emails_followers(): void {
		foreach ( [ 'quick_edit_custom_box', 'bulk_edit_custom_box' ] as $hook ) {
			$this->assertMatchesRegularExpression(
				'#<input type="hidden" name="' . NotifySwitch::FIELD . '" value="0"><label style="[^"]+"><input type="checkbox" name="' . NotifySwitch::FIELD . '" value="1" checked> Email followers when the status changes</label>#',
				$this->edit_box( $hook ),
				$hook
			);
		}

		Settings::update( Settings::NOTIFY_FOLLOWERS, false );
		$this->assertStringNotContainsString( NotifySwitch::FIELD, $this->edit_box( 'quick_edit_custom_box' ) );
	}

	public function test_the_text_about_the_wait_follows_the_real_wait(): void {
		$seconds = 60;
		add_filter(
			StatusNotifier::FILTER_DELAY,
			static function () use ( &$seconds ): int {
				return $seconds;
			}
		);

		$this->assertStringStartsWith( 'The emails go out 1 minute after the last change', NotifySwitch::timing() );
		$seconds = 601;
		$this->assertStringStartsWith( 'The emails go out 11 minutes after the last change', NotifySwitch::timing() );
		$seconds = 0;
		$this->assertSame( 'The emails go out right after the change.', NotifySwitch::timing() );
	}

	public function test_the_edit_screen_announces_a_change_unless_the_box_is_unticked(): void {
		$ticked   = $this->request_with_follower();
		$unticked = $this->request_with_follower();

		$this->save_edit_screen( $ticked, '1', wp_create_nonce( 'update-post_' . $ticked ) );
		$this->save_edit_screen( $unticked, '0', wp_create_nonce( 'update-post_' . $unticked ) );

		$this->assertSame( [ $this->status( 'planned' ) ], $this->term_ids( $unticked, StatusTaxonomy::NAME ), 'The status still changes.' );
		$this->assertSame( [ $ticked ], $this->announced() );
	}

	public function test_an_unticked_box_only_counts_in_a_form_that_wordpress_accepted_for_that_request(): void {
		$forged = $this->request_with_follower();
		$other  = $this->request_with_follower();
		$absent = $this->request_with_follower();

		$this->save_edit_screen( $forged, '0', 'forged' );
		$this->save_edit_screen( $other, '0', wp_create_nonce( 'update-post_' . $forged ) );
		$this->save_edit_screen( $absent, '0', null );

		$this->assertSame( [ $forged, $other, $absent ], $this->announced() );
	}

	public function test_quick_edit_keeps_quiet_when_the_box_is_unticked(): void {
		$ticked   = $this->request_with_follower();
		$unticked = $this->request_with_follower();
		$forged   = $this->request_with_follower();

		$this->quick_edit( $ticked, '1', wp_create_nonce( 'inlineeditnonce' ) );
		$this->quick_edit( $unticked, '0', wp_create_nonce( 'inlineeditnonce' ) );
		// With a forged nonce Quick Edit does not set the status, so the change comes from other code in the same request.
		$this->quick_edit( $forged, '0', 'forged' );
		wp_set_object_terms( $forged, $this->status( 'planned' ), StatusTaxonomy::NAME );

		$this->assertSame( [ $this->status( 'planned' ) ], $this->term_ids( $unticked, StatusTaxonomy::NAME ) );
		$this->assertSame( [ $ticked, $forged ], $this->announced() );
	}

	public function test_bulk_edit_keeps_quiet_when_the_box_is_unticked(): void {
		$loud  = [ $this->request_with_follower(), $this->request_with_follower() ];
		$quiet = [ $this->request_with_follower(), $this->request_with_follower() ];

		$this->bulk_edit( $loud, '1' );
		$this->bulk_edit( $quiet, '0' );

		$this->assertSame( [ $this->status( 'planned' ) ], $this->term_ids( $quiet[1], StatusTaxonomy::NAME ) );
		$this->assertSame( $loud, $this->announced() );
	}

	public function test_the_rest_api_cannot_carry_the_switch(): void {
		$request  = $this->request_with_follower();
		$_POST    = [ NotifySwitch::FIELD => '0' ];
		$_REQUEST = $_POST;
		$update   = new WP_REST_Request( 'POST', rest_get_route_for_post( $request ) );
		$update->set_param( StatusTaxonomy::NAME, [ $this->status( 'planned' ) ] );
		$update->set_param( NotifySwitch::FIELD, '0' );

		$this->assertSame( 200, rest_do_request( $update )->get_status() );
		$this->assertSame( [ $request ], $this->announced() );
	}

	/**
	 * Creates a published request that one user follows.
	 */
	private function request_with_follower(): int {
		$request = $this->create_request();
		$this->create_followers( $request, 1 );

		return $request;
	}

	/**
	 * Returns the requests whose status change has emails waiting, in the order they were scheduled.
	 *
	 * @return int[]
	 */
	private function announced(): array {
		return array_values(
			array_map(
				static function ( $action ): int {
					return $action->get_args()[0];
				},
				$this->waiting_actions( StatusNotifier::HOOK )
			)
		);
	}

	/**
	 * Returns the status box of a request's edit screen.
	 *
	 * @param int $request_id Request ID.
	 */
	private function status_box( int $request_id ): string {
		ob_start();
		TermSelectBox::render( get_post( $request_id ), [ 'args' => [ 'taxonomy' => StatusTaxonomy::NAME ] ] );

		return (string) ob_get_clean();
	}

	/**
	 * Returns what the plugin prints in Quick Edit or Bulk Edit next to the status.
	 *
	 * @param string $hook quick_edit_custom_box or bulk_edit_custom_box.
	 */
	private function edit_box( string $hook ): string {
		ob_start();
		do_action( $hook, RequestList::taxonomy_column( StatusTaxonomy::NAME ), RequestPostType::NAME, '' );

		return (string) ob_get_clean();
	}

	/**
	 * Saves a request from its edit screen with the status set to Planned.
	 *
	 * @param int         $request_id Request ID.
	 * @param string      $notify     What the switch sends: 1 when ticked, 0 when unticked.
	 * @param string|null $nonce      Nonce the form sends, or null for none.
	 */
	private function save_edit_screen( int $request_id, string $notify, ?string $nonce ): void {
		$_POST = [
			'post_ID'           => (string) $request_id,
			'post_type'         => RequestPostType::NAME,
			'post_title'        => 'Dark mode',
			'content'           => 'Easier on the eyes.',
			'tax_input'         => [
				StatusTaxonomy::NAME => [ (string) $this->status( 'planned' ) ],
				BoardTaxonomy::NAME  => [ (string) $this->default_board() ],
			],
			NotifySwitch::FIELD => $notify,
		];
		if ( null !== $nonce ) {
			$_POST['_wpnonce'] = $nonce;
		}
		$_REQUEST = $_POST;

		edit_post();
	}

	/**
	 * Saves a request from Quick Edit with the status set to Planned.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $notify     What the switch sends.
	 * @param string $nonce      Nonce the form sends.
	 */
	private function quick_edit( int $request_id, string $notify, string $nonce ): void {
		$_POST    = [
			'post_ID'              => (string) $request_id,
			'post_type'            => RequestPostType::NAME,
			'post_title'           => 'Dark mode',
			'_status'              => 'publish',
			'_inline_edit'         => $nonce,
			StatusQuickEdit::FIELD => (string) $this->status( 'planned' ),
			NotifySwitch::FIELD    => $notify,
		];
		$_REQUEST = $_POST;

		edit_post();
	}

	/**
	 * Saves requests from Bulk Edit with the status set to Planned.
	 *
	 * @param int[]  $request_ids Request IDs.
	 * @param string $notify      What the switch sends.
	 */
	private function bulk_edit( array $request_ids, string $notify ): void {
		$_POST    = [];
		$_REQUEST = [
			'post'                 => array_map( 'strval', $request_ids ),
			'post_type'            => RequestPostType::NAME,
			'bulk_edit'            => 'Update',
			'_wpnonce'             => wp_create_nonce( 'bulk-posts' ),
			'_status'              => '-1',
			StatusQuickEdit::FIELD => (string) $this->status( 'planned' ),
			NotifySwitch::FIELD    => $notify,
		];

		bulk_edit_posts( $_REQUEST );
	}
}
