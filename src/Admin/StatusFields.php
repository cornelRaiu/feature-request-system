<?php
/**
 * Status settings on the Statuses screens.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_Term;
use WP_Term_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Adds each status's colour, position and "closed for voting" switch to the forms that
 * add and edit a status, shows them as columns, and lists statuses in workflow order.
 */
final class StatusFields {

	/**
	 * Name of the form fields, an array keyed by "color", "order" and "closed".
	 */
	public const FIELD = 'mwtfrs_status_fields';

	/**
	 * Name of the nonce field.
	 */
	public const NONCE_FIELD = 'mwtfrs_status_nonce';

	/**
	 * Nonce action.
	 */
	public const NONCE_ACTION = 'mwtfrs_save_status';

	/**
	 * Key of the colour column.
	 */
	public const COLUMN_COLOR = 'mwtfrs_color';

	/**
	 * Key of the column that says whether a status still takes votes.
	 */
	public const COLUMN_VOTING = 'mwtfrs_voting';

	/**
	 * Adds the fields, the columns and the ordering.
	 */
	public static function add_hooks(): void {
		add_action( StatusTaxonomy::NAME . '_add_form_fields', [ self::class, 'render_on_add' ] );
		add_action( StatusTaxonomy::NAME . '_edit_form_fields', [ self::class, 'render_on_edit' ] );
		// After StatusTaxonomy has given a new status its starting colour and position.
		add_action( 'created_' . StatusTaxonomy::NAME, [ self::class, 'save' ], 20 );
		add_action( 'edited_' . StatusTaxonomy::NAME, [ self::class, 'save' ] );
		add_filter( 'manage_edit-' . StatusTaxonomy::NAME . '_columns', [ self::class, 'columns' ] );
		add_filter( 'manage_' . StatusTaxonomy::NAME . '_custom_column', [ self::class, 'render_column' ], 10, 3 );
		add_action( 'pre_get_terms', [ self::class, 'order_list' ] );
	}

	/**
	 * Prints the fields on the form that adds a status.
	 */
	public static function render_on_add(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		foreach ( self::fields( StatusTaxonomy::DEFAULT_COLOR, '', false ) as $id => list( $label, $control, $help ) ) {
			printf(
				'<div class="form-field">%1$s%2$s<p>%3$s</p></div>',
				self::label( $id, $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in label().
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values in fields().
				esc_html( $help )
			);
		}
	}

	/**
	 * Prints the fields on the form that edits a status.
	 *
	 * @param WP_Term $status Status being edited.
	 */
	public static function render_on_edit( WP_Term $status ): void {
		$fields = self::fields(
			StatusTaxonomy::color( $status ),
			(string) (int) get_term_meta( $status->term_id, StatusTaxonomy::META_ORDER, true ),
			StatusTaxonomy::is_closed( $status )
		);

		foreach ( $fields as $id => list( $label, $control, $help ) ) {
			printf(
				'<tr class="form-field"><th scope="row">%1$s</th><td>%2$s<p class="description">%3$s</p></td></tr>',
				self::label( $id, $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in label().
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values in fields().
				esc_html( $help )
			);
		}

		// Inside a table cell, the only place this form allows more fields.
		echo '<tr class="hidden"><td colspan="2">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '</td></tr>';
	}

	/**
	 * Saves the fields when a status is added or edited from its form.
	 *
	 * Statuses are also created and updated by the REST API, WP-CLI and other code. Those
	 * send no fields, and must leave the status as it is.
	 *
	 * @param int $status_id Status term ID.
	 */
	public static function save( int $status_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_term', $status_id ) ) {
			return;
		}

		$sent  = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? map_deep( wp_unslash( $_POST[ self::FIELD ] ), 'sanitize_text_field' ) : [];
		$color = sanitize_hex_color( (string) ( $sent['color'] ?? '' ) );

		if ( $color ) {
			update_term_meta( $status_id, StatusTaxonomy::META_COLOR, $color );
		}
		// An empty position keeps the one the status has.
		if ( isset( $sent['order'] ) && '' !== $sent['order'] ) {
			update_term_meta( $status_id, StatusTaxonomy::META_ORDER, absint( $sent['order'] ) );
		}
		// An unticked box sends nothing.
		update_term_meta( $status_id, StatusTaxonomy::META_CLOSED, ! empty( $sent['closed'] ) );
	}

	/**
	 * Adds the colour and voting columns after the name.
	 *
	 * @param array<string, string> $columns Columns of the statuses list.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$ordered = [];
		foreach ( $columns as $key => $label ) {
			$ordered[ $key ] = $label;
			if ( 'name' === $key ) {
				$ordered[ self::COLUMN_COLOR ]  = __( 'Colour', 'feature-request-system' );
				$ordered[ self::COLUMN_VOTING ] = __( 'Voting', 'feature-request-system' );
			}
		}

		return $ordered;
	}

	/**
	 * Returns the content of the colour and voting columns.
	 *
	 * @param string $content   Content so far.
	 * @param string $column    Column key.
	 * @param int    $status_id Status term ID.
	 */
	public static function render_column( string $content, string $column, int $status_id ): string {
		$status = get_term( $status_id, StatusTaxonomy::NAME );
		if ( ! $status instanceof WP_Term ) {
			return $content;
		}

		if ( self::COLUMN_COLOR === $column ) {
			return sprintf(
				'<span style="display:inline-block;width:1.25em;height:1.25em;border-radius:50%%;vertical-align:middle;background:%1$s"></span> <code>%1$s</code>',
				esc_attr( StatusTaxonomy::color( $status ) )
			);
		}

		if ( self::COLUMN_VOTING === $column ) {
			return StatusTaxonomy::is_closed( $status )
				? esc_html__( 'Closed', 'feature-request-system' )
				: esc_html__( 'Open', 'feature-request-system' );
		}

		return $content;
	}

	/**
	 * Lists statuses in workflow order on the Statuses screen, unless a column is sorted.
	 *
	 * @param WP_Term_Query $query Terms query about to run.
	 */
	public static function order_list( WP_Term_Query $query ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$vars   = $query->query_vars;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks whether the user sorted a column.
		if ( ! $screen || 'edit-' . StatusTaxonomy::NAME !== $screen->id || [ StatusTaxonomy::NAME ] !== array_values( (array) $vars['taxonomy'] ) || isset( $_GET['orderby'] ) || 'count' === $vars['fields'] ) {
			return;
		}

		// Every status has a position from creation, so none drop out of the list.
		$query->query_vars['meta_key'] = StatusTaxonomy::META_ORDER; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of status terms.
		$query->query_vars['orderby']  = 'meta_value_num';
		$query->query_vars['order']    = 'ASC';

		// WordPress has read the meta arguments by now, and sorts by what it read.
		$query->meta_query->parse_query_vars( $query->query_vars );
	}

	/**
	 * The fields, by HTML id: their label, their control and their help text.
	 *
	 * @param string $color  Colour to show.
	 * @param string $order  Position to show, or '' for a new status.
	 * @param bool   $closed Whether the status is closed for voting.
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	private static function fields( string $color, string $order, bool $closed ): array {
		return [
			'mwtfrs-status-color'  => [
				__( 'Colour', 'feature-request-system' ),
				sprintf( '<input type="color" id="mwtfrs-status-color" name="%1$s" value="%2$s">', esc_attr( self::FIELD . '[color]' ), esc_attr( $color ) ),
				__( 'Shown next to the status wherever a request is listed.', 'feature-request-system' ),
			],
			'mwtfrs-status-order'  => [
				__( 'Position', 'feature-request-system' ),
				sprintf( '<input type="number" min="0" step="1" class="small-text" id="mwtfrs-status-order" name="%1$s" value="%2$s">', esc_attr( self::FIELD . '[order]' ), esc_attr( $order ) ),
				__( 'Statuses are listed from the lowest number to the highest. Leave empty to put a new status last.', 'feature-request-system' ),
			],
			'mwtfrs-status-closed' => [
				__( 'Voting', 'feature-request-system' ),
				sprintf(
					'<label><input type="checkbox" id="mwtfrs-status-closed" name="%1$s" value="1"%2$s> %3$s</label>',
					esc_attr( self::FIELD . '[closed]' ),
					checked( $closed, true, false ),
					esc_html__( 'Closed for voting', 'feature-request-system' )
				),
				__( 'Requests in a closed status keep their votes, and nobody can add or remove one. Use it for statuses such as Shipped or Declined.', 'feature-request-system' ),
			],
		];
	}

	/**
	 * Returns a field's label.
	 *
	 * @param string $id    HTML id of the field.
	 * @param string $label Label text.
	 */
	private static function label( string $id, string $label ): string {
		return sprintf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $label ) );
	}
}
