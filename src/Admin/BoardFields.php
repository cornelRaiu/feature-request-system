<?php
/**
 * Board settings on the Boards screens.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Adds each board's settings to the forms that add and edit a board: who can vote, who
 * can suggest features, whether new requests wait for approval, the board's page and the
 * categories its requests can have. Since 1.1 also how many votes each person can give,
 * and the vote threshold that moves a request to another status.
 */
final class BoardFields {

	/**
	 * Name of the form fields, an array keyed by setting.
	 */
	public const FIELD = 'mwtfrs_board_fields';

	/**
	 * Name of the nonce field.
	 */
	public const NONCE_FIELD = 'mwtfrs_board_nonce';

	/**
	 * Nonce action.
	 */
	public const NONCE_ACTION = 'mwtfrs_save_board';

	/**
	 * HTML id of the one field that is a group of boxes. Its title names the group and is
	 * not the label of a single control.
	 */
	private const GROUP = 'mwtfrs-board-categories';

	/**
	 * Adds the fields and saves them.
	 */
	public static function add_hooks(): void {
		add_action( BoardTaxonomy::NAME . '_add_form_fields', [ self::class, 'render_on_add' ] );
		add_action( BoardTaxonomy::NAME . '_edit_form_fields', [ self::class, 'render_on_edit' ] );
		add_action( 'created_' . BoardTaxonomy::NAME, [ self::class, 'save' ] );
		add_action( 'edited_' . BoardTaxonomy::NAME, [ self::class, 'save' ] );
	}

	/**
	 * Prints the fields on the form that adds a board.
	 */
	public static function render_on_add(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		foreach ( self::fields( BoardSettings::defaults() ) as $id => list( $label, $control, $help ) ) {
			printf(
				'<div class="form-field">%1$s%2$s%3$s</div>',
				// The title of a group of boxes is text on its own line, as a label is on this form.
				self::GROUP === $id ? '<span style="display:block;padding:2px 0">' . esc_html( $label ) . '</span>' : self::label( $id, $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in label().
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values in fields().
				'' !== $help ? '<p>' . esc_html( $help ) . '</p>' : ''
			);
		}
	}

	/**
	 * Prints the fields on the form that edits a board.
	 *
	 * @param WP_Term $board Board being edited.
	 */
	public static function render_on_edit( WP_Term $board ): void {
		foreach ( self::fields( BoardSettings::get( $board->term_id ) ) as $id => list( $label, $control, $help ) ) {
			printf(
				'<tr class="form-field"><th scope="row">%1$s</th><td>%2$s%3$s</td></tr>',
				self::GROUP === $id ? esc_html( $label ) : self::label( $id, $label ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in label().
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values in fields().
				'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		}

		// Inside a table cell, the only place this form allows more fields.
		echo '<tr class="hidden"><td colspan="2">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '</td></tr>';
	}

	/**
	 * Saves the fields when a board is added or edited from its form.
	 *
	 * Boards are also created and updated by the REST API, WP-CLI and other code. Those
	 * send no fields, and must leave the board's settings as they are.
	 *
	 * @param int $board_id Board term ID.
	 */
	public static function save( int $board_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_term', $board_id ) ) {
			return;
		}

		$sent = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? map_deep( wp_unslash( $_POST[ self::FIELD ] ), 'sanitize_text_field' ) : [];

		BoardSettings::update(
			$board_id,
			[
				BoardSettings::VOTING     => $sent[ BoardSettings::VOTING ] ?? '',
				BoardSettings::SUBMITTING => $sent[ BoardSettings::SUBMITTING ] ?? '',
				// An unticked box sends nothing.
				BoardSettings::MODERATION => ! empty( $sent[ BoardSettings::MODERATION ] ),
				BoardSettings::PAGE       => absint( $sent[ BoardSettings::PAGE ] ?? 0 ),
				// No ticked box sends nothing, which means every category.
				BoardSettings::CATEGORIES => isset( $sent[ BoardSettings::CATEGORIES ] ) && is_array( $sent[ BoardSettings::CATEGORIES ] ) ? $sent[ BoardSettings::CATEGORIES ] : [],
				// An empty field sends '', which means no limit, and no threshold.
				BoardSettings::VOTE_LIMIT => absint( $sent[ BoardSettings::VOTE_LIMIT ] ?? 0 ),
				BoardSettings::THRESHOLD  => isset( $sent[ BoardSettings::THRESHOLD ] ) && is_array( $sent[ BoardSettings::THRESHOLD ] ) ? $sent[ BoardSettings::THRESHOLD ] : [],
			]
		);
	}

	/**
	 * The fields, by HTML id: their label, their control and their help text.
	 *
	 * @param array<string, string|bool|int|int[]> $settings Settings to show.
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	private static function fields( array $settings ): array {
		$who = [
			BoardSettings::ANYONE    => __( 'Everyone, including visitors without an account', 'feature-request-system' ),
			BoardSettings::LOGGED_IN => __( 'Logged-in users', 'feature-request-system' ),
			BoardSettings::NOBODY    => __( 'Nobody', 'feature-request-system' ),
		];

		$page = (string) wp_dropdown_pages(
			[
				'echo'              => 0,
				'name'              => esc_attr( self::name( BoardSettings::PAGE ) ),
				'id'                => 'mwtfrs-board-page',
				'selected'          => (int) $settings[ BoardSettings::PAGE ],
				'show_option_none'  => esc_html__( '&mdash; Not set &mdash;', 'feature-request-system' ),
				'option_none_value' => '0',
			]
		);

		return [
			'mwtfrs-board-voting'     => [
				__( 'Who can vote', 'feature-request-system' ),
				self::select( 'mwtfrs-board-voting', BoardSettings::VOTING, $who, (string) $settings[ BoardSettings::VOTING ] ),
				'',
			],
			'mwtfrs-board-vote-limit' => [
				__( 'Votes each person can give', 'feature-request-system' ),
				sprintf(
					'<input type="number" id="mwtfrs-board-vote-limit" class="small-text" name="%1$s" value="%2$s" min="1" step="1">',
					esc_attr( self::name( BoardSettings::VOTE_LIMIT ) ),
					(int) $settings[ BoardSettings::VOTE_LIMIT ] ? (int) $settings[ BoardSettings::VOTE_LIMIT ] : ''
				),
				__( 'Leave empty for no limit. Only votes for requests that are still open for voting count, so a vote comes back when its request is shipped or declined.', 'feature-request-system' ),
			],
			'mwtfrs-board-threshold'  => [
				__( 'Vote threshold', 'feature-request-system' ),
				self::threshold( (array) $settings[ BoardSettings::THRESHOLD ] ),
				__( 'Leave the number empty to move nothing. A request is moved once, so a moderator can move it back, and its followers are emailed as for any change of status.', 'feature-request-system' ),
			],
			'mwtfrs-board-submitting' => [
				__( 'Who can suggest features', 'feature-request-system' ),
				self::select( 'mwtfrs-board-submitting', BoardSettings::SUBMITTING, $who, (string) $settings[ BoardSettings::SUBMITTING ] ),
				'',
			],
			'mwtfrs-board-moderation' => [
				__( 'New requests', 'feature-request-system' ),
				sprintf(
					'<label><input type="checkbox" id="mwtfrs-board-moderation" name="%1$s" value="1"%2$s> %3$s</label>',
					esc_attr( self::name( BoardSettings::MODERATION ) ),
					checked( (bool) $settings[ BoardSettings::MODERATION ], true, false ),
					esc_html__( 'Hold new requests until a moderator approves them', 'feature-request-system' )
				),
				__( 'Requests from editors and administrators are always published straight away.', 'feature-request-system' ),
			],
			'mwtfrs-board-page'       => [
				__( 'Board page', 'feature-request-system' ),
				// No pages yet: nothing to choose from.
				'' !== $page ? $page : esc_html__( 'No pages yet.', 'feature-request-system' ),
				__( 'The page that shows this board. Each request links back to it. Filled in by itself the first time the board is shown on a page.', 'feature-request-system' ),
			],
			self::GROUP               => [
				__( 'Categories', 'feature-request-system' ),
				self::category_boxes( array_map( 'intval', (array) $settings[ BoardSettings::CATEGORIES ] ) ),
				__( 'The categories that people can choose for a request on this board, and that the board can be filtered by. Tick none to offer every category.', 'feature-request-system' ),
			],
		];
	}

	/**
	 * Returns the threshold's controls, as one sentence: when a request reaches a number of
	 * votes, move it from one status to another.
	 *
	 * @param array<string, int> $threshold Saved threshold: votes, from and to.
	 */
	private static function threshold( array $threshold ): string {
		$statuses = get_terms(
			[
				'taxonomy'   => StatusTaxonomy::NAME,
				'hide_empty' => false,
			]
		);
		$statuses = is_array( $statuses ) ? StatusTaxonomy::sort( $statuses ) : [];
		$options  = wp_list_pluck( $statuses, 'name', 'term_id' );
		$ids      = array_keys( $options );

		// Until one is saved, the threshold suggests moving new requests on to the next status.
		$default = RequestTerms::default_term( StatusTaxonomy::NAME );
		$next    = array_search( $default, $ids, true );
		$from    = ! empty( $threshold['from'] ) ? (int) $threshold['from'] : $default;
		$to      = ! empty( $threshold['to'] ) ? (int) $threshold['to'] : (int) ( $ids[ false === $next ? 0 : $next + 1 ] ?? 0 );
		$votes   = (int) ( $threshold['votes'] ?? 0 );

		$status_select = static function ( string $part, string $label, int $selected ) use ( $options ): string {
			$html = sprintf( '<select name="%1$s[%2$s]" aria-label="%3$s">', esc_attr( self::name( BoardSettings::THRESHOLD ) ), esc_attr( $part ), esc_attr( $label ) );
			foreach ( $options as $id => $name ) {
				$html .= sprintf( '<option value="%1$d"%2$s>%3$s</option>', (int) $id, selected( $selected, (int) $id, false ), esc_html( $name ) );
			}

			return $html . '</select>';
		};

		return sprintf(
			/* translators: 1: field for a number of votes, 2: dropdown of statuses to move from, 3: dropdown of statuses to move to. */
			esc_html__( 'When a request reaches %1$s votes, move it from %2$s to %3$s.', 'feature-request-system' ),
			sprintf(
				'<input type="number" id="mwtfrs-board-threshold" class="small-text" name="%1$s[votes]" value="%2$s" min="1" step="1">',
				esc_attr( self::name( BoardSettings::THRESHOLD ) ),
				$votes ? $votes : ''
			),
			$status_select( 'from', __( 'Status to move the request from', 'feature-request-system' ), $from ),
			$status_select( 'to', __( 'Status to move the request to', 'feature-request-system' ), $to )
		);
	}

	/**
	 * Returns a checkbox for every category.
	 *
	 * @param int[] $chosen Term IDs of the ticked categories.
	 */
	private static function category_boxes( array $chosen ): string {
		$categories = get_terms(
			[
				'taxonomy'   => CategoryTaxonomy::NAME,
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);
		if ( ! is_array( $categories ) || ! $categories ) {
			return esc_html__( 'No categories yet.', 'feature-request-system' );
		}

		$html = '<fieldset id="' . esc_attr( self::GROUP ) . '"><legend class="screen-reader-text">' . esc_html__( 'Categories', 'feature-request-system' ) . '</legend>';
		foreach ( $categories as $category ) {
			$html .= sprintf(
				'<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$d"%3$s> %4$s</label>',
				esc_attr( self::name( BoardSettings::CATEGORIES ) ),
				(int) $category->term_id,
				checked( in_array( $category->term_id, $chosen, true ), true, false ),
				esc_html( $category->name )
			);
		}

		return $html . '</fieldset>';
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

	/**
	 * Returns a dropdown.
	 *
	 * @param string                $id       HTML id.
	 * @param string                $setting  Setting the dropdown is for.
	 * @param array<string, string> $options  Labels by value.
	 * @param string                $selected Selected value.
	 */
	private static function select( string $id, string $setting, array $options, string $selected ): string {
		$html = sprintf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( self::name( $setting ) ) );
		foreach ( $options as $value => $label ) {
			$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}

		return $html . '</select>';
	}

	/**
	 * Name of a setting's form field.
	 *
	 * @param string $setting Setting key.
	 */
	private static function name( string $setting ): string {
		return self::FIELD . '[' . $setting . ']';
	}
}
