<?php
/**
 * Block, shortcode and pattern registration.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Plugin;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the blocks from the build folder, the shortcodes that render them, and a
 * pattern for a complete feature request page.
 */
final class Blocks {

	/**
	 * Board shortcode tag, as in [feature_requests board="mobile-app"].
	 */
	public const SHORTCODE = 'feature_requests';

	/**
	 * Form shortcode tag, as in [feature_request_form board="mobile-app"].
	 */
	public const FORM_SHORTCODE = 'feature_request_form';

	/**
	 * Pattern with the form above the board.
	 */
	public const PATTERN = 'mwtfrs/feature-request-page';

	/**
	 * Pattern category for the plugin's patterns.
	 */
	public const PATTERN_CATEGORY = 'mwtfrs-feature-requests';

	/**
	 * Filter to turn off the plugin's stylesheets, for sites that style the blocks
	 * themselves. Return false. It is read on init, so add it before that.
	 */
	public const FILTER_STYLES = 'mwtfrs_load_styles';

	/**
	 * Block folders inside build/.
	 */
	private const BLOCK_FOLDERS = [ 'board', 'submit', 'request' ];

	/**
	 * Registers the blocks, shortcodes and pattern. Runs on init.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, [ self::class, 'shortcode' ] );
		add_shortcode( self::FORM_SHORTCODE, [ self::class, 'form_shortcode' ] );

		$styled = (bool) apply_filters( self::FILTER_STYLES, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		foreach ( self::BLOCK_FOLDERS as $folder ) {
			$path = Plugin::path( 'build/' . $folder );
			if ( ! is_readable( $path . '/block.json' ) ) {
				add_action( 'admin_notices', [ self::class, 'missing_build_notice' ] );
				return;
			}

			$block = register_block_type( $path );
			if ( $block && ! $styled ) {
				$block->style_handles = [];
			}
		}

		self::register_pattern();
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

		return self::render(
			Board::BLOCK,
			[
				'board'   => self::board_id( $atts['board'] ),
				'perPage' => absint( $atts['per_page'] ),
			]
		);
	}

	/**
	 * Renders the form block for the form shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: board (slug or term ID).
	 */
	public static function form_shortcode( $atts ): string {
		$atts = shortcode_atts(
			[ 'board' => '' ],
			is_array( $atts ) ? $atts : [],
			self::FORM_SHORTCODE
		);

		return self::render( SubmitForm::BLOCK, [ 'board' => self::board_id( $atts['board'] ) ] );
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
			esc_html__( 'Feature Request System cannot show its blocks because the block files are missing. Run npm install and npm run build, or reinstall the plugin from WordPress.org.', 'feature-request-system' )
		);
	}

	/**
	 * Registers the feature request page pattern: the form, then the board under its own heading.
	 */
	private static function register_pattern(): void {
		register_block_pattern_category(
			self::PATTERN_CATEGORY,
			[ 'label' => __( 'Feature requests', 'feature-request-system' ) ]
		);

		$heading = '<!-- wp:heading -->' . "\n"
			. '<h2 class="wp-block-heading">' . esc_html__( 'Browse requests', 'feature-request-system' ) . '</h2>' . "\n"
			. '<!-- /wp:heading -->';

		register_block_pattern(
			self::PATTERN,
			[
				'title'       => __( 'Feature request page', 'feature-request-system' ),
				'description' => __( 'A form for suggesting features, above the board where people vote.', 'feature-request-system' ),
				'categories'  => [ self::PATTERN_CATEGORY ],
				'keywords'    => [ 'feature request', 'roadmap', 'feedback', 'ideas', 'vote' ],
				'content'     => implode(
					"\n\n",
					[
						'<!-- wp:' . SubmitForm::BLOCK . ' /-->',
						$heading,
						'<!-- wp:' . Board::BLOCK . ' /-->',
					]
				),
			]
		);
	}

	/**
	 * Renders a block, as the shortcodes do.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private static function render( string $name, array $attrs ): string {
		return render_block(
			[
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Finds a board from a shortcode attribute. 0, for the default board, when there is no match.
	 *
	 * @param string $board Board slug or term ID.
	 */
	private static function board_id( string $board ): int {
		$term = is_numeric( $board )
			? get_term( (int) $board, BoardTaxonomy::NAME )
			: get_term_by( 'slug', sanitize_title( $board ), BoardTaxonomy::NAME );

		return $term instanceof WP_Term ? $term->term_id : 0;
	}
}
