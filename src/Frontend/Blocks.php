<?php
/**
 * Block and shortcode registration.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Plugin;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the board block from the build folder, and the shortcode that renders it.
 */
final class Blocks {

	/**
	 * Shortcode tag, as in [feature_requests board="mobile-app"].
	 */
	public const SHORTCODE = 'feature_requests';

	/**
	 * Registers the block and the shortcode. Runs on init.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, [ self::class, 'shortcode' ] );

		$board = Plugin::path( 'build/board' );
		if ( is_readable( $board . '/block.json' ) ) {
			register_block_type( $board );
			return;
		}

		add_action( 'admin_notices', [ self::class, 'missing_build_notice' ] );
	}

	/**
	 * Renders the board block for the shortcode, so both share the same markup, assets and behaviour.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: board (slug or term ID) and per_page.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'board'    => '',
				'per_page' => '20',
			],
			is_array( $atts ) ? $atts : [],
			self::SHORTCODE
		);

		$board = is_numeric( $atts['board'] )
			? get_term( (int) $atts['board'], BoardTaxonomy::NAME )
			: get_term_by( 'slug', sanitize_title( $atts['board'] ), BoardTaxonomy::NAME );

		return render_block(
			[
				'blockName'    => Board::BLOCK,
				'attrs'        => [
					'board'   => $board instanceof WP_Term ? $board->term_id : 0,
					'perPage' => absint( $atts['per_page'] ),
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Tells administrators that the compiled block files are missing, as in a copy of the source code.
	 */
	public static function missing_build_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Feature Request System cannot show its board because the block files are missing. Run npm install and npm run build, or reinstall the plugin from WordPress.org.', 'feature-request-system' )
		);
	}
}
