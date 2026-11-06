<?php
/**
 * Export buttons on the requests list.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Export\CsvExport;

defined( 'ABSPATH' ) || exit;

/**
 * Puts "Export to CSV" and "Export voters to CSV" above the requests list, and sends the file.
 *
 * Each button is a link that carries the list's filters, search and sort order, so the
 * file holds what the list shows. The link goes to admin-post.php with a nonce, and
 * works without JavaScript. Who submitted a request and the emails of voters are in the
 * file only for those who can edit others' requests.
 */
final class ExportLinks {

	/**
	 * The admin-post action, also used as the nonce action.
	 */
	public const ACTION = 'mwtfrs_export';

	/**
	 * Query argument that asks for the voters instead of the requests.
	 */
	public const PARAM = 'export';

	/**
	 * Value of PARAM for the voters.
	 */
	public const VOTERS = 'voters';

	/**
	 * Adds the buttons and the handler that sends the file.
	 */
	public static function add_hooks(): void {
		add_action( 'manage_posts_extra_tablenav', [ self::class, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Prints the buttons above the requests list.
	 *
	 * @param string $which Where the list prints its navigation: top or bottom.
	 */
	public static function render( string $which ): void {
		$screen = get_current_screen();
		if ( 'top' !== $which || ! $screen || RequestPostType::NAME !== $screen->post_type || ! CsvExport::can_export( get_current_user_id() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters of a list.
		$filters = CsvExport::filters( wp_unslash( $_GET ) );

		printf(
			'<div class="alignleft actions"><a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></div>',
			esc_url( self::url( $filters, false ) ),
			esc_html__( 'Export to CSV', 'feature-request-system' ),
			esc_url( self::url( $filters, true ) ),
			esc_html__( 'Export voters to CSV', 'feature-request-system' )
		);
	}

	/**
	 * Returns the address that downloads the requests, or their voters, that match the filters.
	 *
	 * @param array<string, string> $filters Filters from CsvExport::filters().
	 * @param bool                  $voters  Whether to download the voters.
	 */
	public static function url( array $filters, bool $voters ): string {
		$args = array_filter(
			$filters,
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);
		if ( $voters ) {
			$args[ self::PARAM ] = self::VOTERS;
		}
		$args['action'] = self::ACTION;

		// Without its HTML escaping, which the caller adds when it prints the address.
		return str_replace( '&amp;', '&', wp_nonce_url( add_query_arg( urlencode_deep( $args ), admin_url( 'admin-post.php' ) ), self::ACTION ) );
	}

	/**
	 * Sends the file and ends the request.
	 */
	public static function handle(): void {
		self::download( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- download() checks the nonce.
		exit;
	}

	/**
	 * Checks the link is genuine and the user may export, then prints the file.
	 *
	 * @param array<string, mixed> $input Query arguments of the link.
	 */
	public static function download( array $input ): void {
		if ( ! wp_verify_nonce( (string) ( $input['_wpnonce'] ?? '' ), self::ACTION ) ) {
			wp_nonce_ays( self::ACTION );
		}
		if ( ! CsvExport::can_export( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You are not allowed to export feature requests.', 'feature-request-system' ), '', [ 'response' => 403 ] );
		}

		$voters = self::VOTERS === ( $input[ self::PARAM ] ?? '' );
		$name   = $voters ? 'feature-request-voters' : 'feature-requests';

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $name . '-' . wp_date( 'Y-m-d' ) . '.csv"' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		// A byte order mark, without which Excel reads the file in the computer's old code page.
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV file, not HTML.

		$write       = static function ( string $csv ): void {
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV file, not HTML; CsvExport::line() guards each cell.
		};
		$with_emails = CsvExport::with_emails( get_current_user_id() );
		$filters     = CsvExport::filters( $input );

		if ( $voters ) {
			CsvExport::voters( $filters, $with_emails, $write );
		} else {
			CsvExport::requests( $filters, $with_emails, $write );
		}
	}
}
