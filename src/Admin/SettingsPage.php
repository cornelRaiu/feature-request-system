<?php
/**
 * The settings page.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Settings;
use FeatureRequestSystem\Submission\DataUseNote;

defined( 'ABSPATH' ) || exit;

/**
 * Feature Requests → Settings, built with the Settings API.
 */
final class SettingsPage {

	/**
	 * Slug of the page, as in edit.php?post_type=…&page=….
	 */
	public const SLUG = 'mwtfrs-settings';

	/**
	 * Option group the settings form saves.
	 */
	public const GROUP = 'mwtfrs_options';

	/**
	 * Adds the page and registers what it saves.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_init', [ self::class, 'register' ] );
		add_filter( 'option_page_capability_' . self::GROUP, [ self::class, 'capability' ] );
	}

	/**
	 * Adds Settings under Feature Requests.
	 */
	public static function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . RequestPostType::NAME,
			__( 'Feature Request Settings', 'feature-request-system' ),
			__( 'Settings', 'feature-request-system' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Lets everyone who can open the page save it.
	 */
	public static function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Registers the option, its sections and its fields.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
			]
		);

		$sections = [
			'requests'  => [ __( 'New requests', 'feature-request-system' ), '' ],
			'guests'    => [ __( 'Visitors without an account', 'feature-request-system' ), __( 'Each board decides whether visitors without an account can vote or suggest features. Set that on the board, under Feature Requests → Boards.', 'feature-request-system' ) ],
			'uninstall' => [ __( 'Uninstalling', 'feature-request-system' ), '' ],
		];
		foreach ( $sections as $id => list( $title, $intro ) ) {
			add_settings_section(
				$id,
				$title,
				static function () use ( $intro ): void {
					if ( '' !== $intro ) {
						echo '<p>' . esc_html( $intro ) . '</p>';
					}
				},
				self::SLUG
			);
		}

		$fields = [
			Settings::DEFAULT_BOARD            => [ __( 'Default board', 'feature-request-system' ), 'requests', 'render_default_board' ],
			Settings::DEFAULT_STATUS           => [ __( 'Status of new requests', 'feature-request-system' ), 'requests', 'render_default_status' ],
			Settings::NOTIFY_NEW_REQUESTS      => [ __( 'Email about new requests', 'feature-request-system' ), 'requests', 'render_notify' ],
			Settings::NOTIFY_EMAILS            => [ __( 'Send those emails to', 'feature-request-system' ), 'requests', 'render_notify_emails' ],
			Settings::DATA_USE_NOTE            => [ __( 'How we use your data', 'feature-request-system' ), 'guests', 'render_data_use_note' ],
			Settings::DELETE_DATA_ON_UNINSTALL => [ __( 'Data', 'feature-request-system' ), 'uninstall', 'render_delete_data' ],
		];
		foreach ( $fields as $key => list( $title, $section, $callback ) ) {
			$labelled = in_array( $key, [ Settings::NOTIFY_NEW_REQUESTS, Settings::DELETE_DATA_ON_UNINSTALL ], true ) ? [] : [ 'label_for' => self::id( $key ) ];
			add_settings_field( $key, $title, [ self::class, $callback ], self::SLUG, $section, $labelled );
		}
	}

	/**
	 * Cleans what the form sent. Also runs when code saves the option in the admin.
	 *
	 * Settings that were not sent keep their value, so saving one setting from code
	 * cannot reset the others. The form's checkboxes send 0 when they are unticked.
	 *
	 * @param mixed $input Settings as sent.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = [];

		foreach ( Settings::defaults() as $key => $default ) {
			$clean[ $key ] = array_key_exists( $key, $input ) ? self::sanitize_value( $key, $input[ $key ] ) : Settings::get( $key );
		}

		return $clean;
	}

	/**
	 * Prints the page.
	 */
	public static function render(): void {
		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		// WordPress prints "Settings saved." and errors by itself only on pages under its Settings menu.
		settings_errors();
		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Prints the board that requests go to when none is chosen.
	 */
	public static function render_default_board(): void {
		self::term_select( Settings::DEFAULT_BOARD, BoardTaxonomy::NAME );
		echo '<p class="description">' . esc_html__( 'Requests go to this board when none is chosen. It cannot be deleted.', 'feature-request-system' ) . '</p>';
	}

	/**
	 * Prints the status that new requests start in.
	 */
	public static function render_default_status(): void {
		self::term_select( Settings::DEFAULT_STATUS, StatusTaxonomy::NAME );
		echo '<p class="description">' . esc_html__( 'Every new request starts in this status. It cannot be deleted.', 'feature-request-system' ) . '</p>';
	}

	/**
	 * Prints the switch for new request emails.
	 */
	public static function render_notify(): void {
		self::checkbox( Settings::NOTIFY_NEW_REQUESTS, __( 'Email the team when someone suggests a feature', 'feature-request-system' ) );
	}

	/**
	 * Prints who gets the new request emails.
	 */
	public static function render_notify_emails(): void {
		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s"><p class="description">%5$s</p>',
			esc_attr( self::id( Settings::NOTIFY_EMAILS ) ),
			esc_attr( self::name( Settings::NOTIFY_EMAILS ) ),
			esc_attr( (string) Settings::get( Settings::NOTIFY_EMAILS ) ),
			esc_attr( (string) get_option( 'admin_email' ) ),
			esc_html__( 'One or more email addresses, separated by commas. Leave empty to use the site\'s administration email address.', 'feature-request-system' )
		);
	}

	/**
	 * Prints the note for visitors who suggest a feature without an account.
	 */
	public static function render_data_use_note(): void {
		printf(
			'<textarea class="large-text" rows="5" id="%1$s" name="%2$s">%3$s</textarea><p class="description">%4$s</p><p class="description">%5$s</p>',
			esc_attr( self::id( Settings::DATA_USE_NOTE ) ),
			esc_attr( self::name( Settings::DATA_USE_NOTE ) ),
			esc_textarea( (string) Settings::get( Settings::DATA_USE_NOTE ) ),
			esc_html__( 'Shown on the form to visitors without an account, under the name and email fields, behind a "How we use your data" link. A link to your privacy policy page follows it. Links and emphasis are allowed. Leave empty to show nothing.', 'feature-request-system' ),
			/* translators: %s: suggested text. */
			esc_html( sprintf( __( 'A text to start from: %s', 'feature-request-system' ), DataUseNote::suggestion() ) )
		);
	}

	/**
	 * Prints the switch for deleting data on uninstall.
	 */
	public static function render_delete_data(): void {
		self::checkbox( Settings::DELETE_DATA_ON_UNINSTALL, __( 'Delete all requests, votes, boards, statuses and settings when the plugin is deleted', 'feature-request-system' ) );
	}

	/**
	 * Cleans one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value as sent.
	 * @return mixed
	 */
	private static function sanitize_value( string $key, $value ) {
		switch ( $key ) {
			case Settings::DEFAULT_BOARD:
				return self::term_or_current( $key, $value, BoardTaxonomy::NAME );
			case Settings::DEFAULT_STATUS:
				return self::term_or_current( $key, $value, StatusTaxonomy::NAME );
			case Settings::NOTIFY_EMAILS:
				return self::emails( is_string( $value ) ? $value : '' );
			case Settings::DATA_USE_NOTE:
				return DataUseNote::sanitize( is_string( $value ) ? $value : '' );
			default:
				return ! empty( $value );
		}
	}

	/**
	 * Returns the term ID when it is a term of the taxonomy, otherwise the current setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $value    Term ID as sent.
	 * @param string $taxonomy Taxonomy the term must belong to.
	 */
	private static function term_or_current( string $key, $value, string $taxonomy ): int {
		$term_id = is_numeric( $value ) ? (int) $value : -1;

		// 0 is valid: it is what a taxonomy without terms has as its default.
		return 0 === $term_id || ( $term_id > 0 && term_exists( $term_id, $taxonomy ) ) ? $term_id : (int) Settings::get( $key );
	}

	/**
	 * Keeps the valid addresses of a comma-separated list, and tells the user which were left out.
	 *
	 * @param string $addresses Addresses as typed.
	 */
	private static function emails( string $addresses ): string {
		$typed   = array_filter( array_map( 'trim', (array) preg_split( '/[\s,;]+/', $addresses ) ) );
		$valid   = array_filter( $typed, 'is_email' );
		$invalid = array_diff( $typed, $valid );

		if ( $invalid && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				Settings::OPTION,
				Settings::NOTIFY_EMAILS,
				/* translators: %s: list of email addresses. */
				sprintf( __( 'These are not valid email addresses and were left out: %s', 'feature-request-system' ), implode( ', ', $invalid ) )
			);
		}

		return implode( ', ', $valid );
	}

	/**
	 * Prints a dropdown of a taxonomy's terms.
	 *
	 * @param string $key      Setting key.
	 * @param string $taxonomy Taxonomy name.
	 */
	private static function term_select( string $key, string $taxonomy ): void {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);
		$terms = is_array( $terms ) ? $terms : [];
		if ( StatusTaxonomy::NAME === $taxonomy ) {
			$terms = StatusTaxonomy::sort( $terms );
		}

		printf( '<select id="%1$s" name="%2$s">', esc_attr( self::id( $key ) ), esc_attr( self::name( $key ) ) );
		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $term->term_id,
				selected( (int) Settings::get( $key ), $term->term_id, false ),
				esc_html( $term->name )
			);
		}
		echo '</select>';
	}

	/**
	 * Prints a checkbox that sends 0 when it is unticked.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label next to the box.
	 */
	private static function checkbox( string $key, string $label ): void {
		printf(
			'<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr( self::name( $key ) ),
			checked( (bool) Settings::get( $key ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Name of a setting's form field.
	 *
	 * @param string $key Setting key.
	 */
	private static function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * HTML id of a setting's form field.
	 *
	 * @param string $key Setting key.
	 */
	private static function id( string $key ): string {
		return self::SLUG . '-' . str_replace( '_', '-', $key );
	}
}
