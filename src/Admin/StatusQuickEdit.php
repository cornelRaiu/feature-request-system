<?php
/**
 * Status in Quick Edit and Bulk Edit.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Lets moderators change the status of one request from Quick Edit, or of many from Bulk Edit.
 *
 * WordPress would offer a free-text tags field for the status taxonomy here. A request has
 * exactly one status, so this adds a dropdown instead.
 */
final class StatusQuickEdit {

	/**
	 * Name of the dropdown, also the class of the element that holds a row's current status.
	 */
	public const FIELD = 'mwtfrs_new_status';

	/**
	 * Handle of the script that selects the current status when Quick Edit opens.
	 */
	private const SCRIPT = 'mwtfrs-quick-edit';

	/**
	 * Adds the hooks for both forms.
	 */
	public static function add_hooks(): void {
		add_action( 'quick_edit_custom_box', [ self::class, 'render_quick_edit' ], 10, 2 );
		add_action( 'bulk_edit_custom_box', [ self::class, 'render_bulk_edit' ], 10, 2 );
		add_action( 'add_inline_data', [ self::class, 'add_current_status' ] );
		add_action( 'save_post_' . RequestPostType::NAME, [ self::class, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_script' ] );
	}

	/**
	 * Prints the dropdown in Quick Edit.
	 *
	 * @param string $column    Column the form is asking about.
	 * @param string $post_type Post type of the list.
	 */
	public static function render_quick_edit( string $column, string $post_type ): void {
		self::render( $column, $post_type, '' );
	}

	/**
	 * Prints the dropdown in Bulk Edit, where leaving it alone keeps each request's status.
	 *
	 * @param string $column    Column the form is asking about.
	 * @param string $post_type Post type of the list.
	 */
	public static function render_bulk_edit( string $column, string $post_type ): void {
		self::render( $column, $post_type, __( '&mdash; No Change &mdash;', 'feature-request-system' ) );
	}

	/**
	 * Adds a request's current status to the hidden data of its row, where Quick Edit reads it.
	 *
	 * @param WP_Post $post Post of the row.
	 */
	public static function add_current_status( WP_Post $post ): void {
		if ( RequestPostType::NAME !== $post->post_type ) {
			return;
		}

		$status = RequestTerms::term_of( $post->ID, StatusTaxonomy::NAME );

		printf( '<div class="%1$s">%2$d</div>', esc_attr( self::FIELD ), $status ? (int) $status->term_id : 0 );
	}

	/**
	 * Sets the chosen status when a request is saved from Quick Edit or Bulk Edit.
	 *
	 * @param int $post_id Request ID.
	 */
	public static function save( int $post_id ): void {
		if ( ! self::is_quick_or_bulk_edit() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in is_quick_or_bulk_edit().
		$status   = isset( $_REQUEST[ self::FIELD ] ) ? absint( $_REQUEST[ self::FIELD ] ) : 0;
		$taxonomy = get_taxonomy( StatusTaxonomy::NAME );

		if ( $status && $taxonomy && current_user_can( $taxonomy->cap->assign_terms ) && current_user_can( 'edit_post', $post_id ) && term_exists( $status, StatusTaxonomy::NAME ) ) {
			wp_set_object_terms( $post_id, $status, StatusTaxonomy::NAME );
		}
	}

	/**
	 * Loads the script on the requests list.
	 *
	 * @param string $screen File name of the admin screen.
	 */
	public static function enqueue_script( string $screen ): void {
		if ( 'edit.php' === $screen && RequestPostType::NAME === get_current_screen()->post_type ) {
			wp_enqueue_script( self::SCRIPT, Plugin::url( 'assets/admin/quick-edit.js' ), [ 'inline-edit-post' ], Plugin::VERSION, true );
		}
	}

	/**
	 * Prints the dropdown next to the Status column's place in the form.
	 *
	 * @param string $column    Column the form is asking about.
	 * @param string $post_type Post type of the list.
	 * @param string $no_change Label of an option that leaves the status alone, or '' for none.
	 */
	private static function render( string $column, string $post_type, string $no_change ): void {
		$taxonomy = get_taxonomy( StatusTaxonomy::NAME );
		if ( RequestList::taxonomy_column( StatusTaxonomy::NAME ) !== $column || RequestPostType::NAME !== $post_type || ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return;
		}

		$statuses = get_terms(
			[
				'taxonomy'   => StatusTaxonomy::NAME,
				'hide_empty' => false,
			]
		);

		printf(
			// The label is longer than the column WordPress gives labels here, so it sizes itself.
			'<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label class="inline-edit-group"><span class="title" style="width:auto;margin-right:0.5em;white-space:nowrap">%1$s</span><select name="%2$s">',
			// Not "Status": WordPress has a field with that name here, for Published, Pending and so on.
			esc_html__( 'Request status', 'feature-request-system' ),
			esc_attr( self::FIELD )
		);
		if ( '' !== $no_change ) {
			printf( '<option value="">%s</option>', esc_html( html_entity_decode( $no_change, ENT_QUOTES, 'UTF-8' ) ) );
		}
		foreach ( StatusTaxonomy::sort( is_array( $statuses ) ? $statuses : [] ) as $status ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $status->term_id, esc_html( $status->name ) );
		}
		echo '</select></label>';

		if ( Subscriptions::enabled() ) {
			printf(
				'<div class="inline-edit-group">%1$s<p class="description">%2$s</p></div>',
				// WordPress gives labels here the height of a text field, which pulls the lines of a long label apart.
				NotifySwitch::field( __( 'Email followers when the status changes', 'feature-request-system' ), 'line-height:1.5;margin:0.6em 0' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field().
				esc_html( NotifySwitch::timing() )
			);
		}

		echo '</div></fieldset>';
	}

	/**
	 * Whether the current request is a Quick Edit or Bulk Edit save that WordPress accepted.
	 */
	public static function is_quick_or_bulk_edit(): bool {
		$quick = isset( $_POST['_inline_edit'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' );
		$bulk  = isset( $_REQUEST['bulk_edit'], $_REQUEST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-posts' );

		return $quick || $bulk;
	}
}
