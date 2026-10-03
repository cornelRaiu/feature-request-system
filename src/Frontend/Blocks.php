<?php
/**
 * Block, shortcode and pattern registration.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Plugin;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the blocks from the build folder, the shortcodes that render them, and the
 * patterns that put them on a page.
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
	 * List shortcode tag, as in [feature_request_list count="5" board="mobile-app"].
	 */
	public const LIST_SHORTCODE = 'feature_request_list';

	/**
	 * Card shortcode tag, as in [feature_request id="123"].
	 */
	public const CARD_SHORTCODE = 'feature_request';

	/**
	 * Pattern with the form above the board.
	 */
	public const PATTERN = 'mwtfrs/feature-request-page';

	/**
	 * Pattern with the form in a column beside the board.
	 */
	public const PATTERN_COLUMNS = 'mwtfrs/form-beside-board';

	/**
	 * Pattern with a heading above the most-voted requests.
	 */
	public const PATTERN_MOST_WANTED = 'mwtfrs/most-wanted';

	/**
	 * Pattern with an invitation and a button that leads to the board's page.
	 */
	public const PATTERN_INVITATION = 'mwtfrs/suggest-a-feature';

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
	private const BLOCK_FOLDERS = [ 'board', 'submit', 'request', 'request-card' ];

	/**
	 * Registers the blocks, shortcodes and patterns. Runs on init.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, [ self::class, 'shortcode' ] );
		add_shortcode( self::FORM_SHORTCODE, [ self::class, 'form_shortcode' ] );
		add_shortcode( self::LIST_SHORTCODE, [ self::class, 'list_shortcode' ] );
		add_shortcode( self::CARD_SHORTCODE, [ self::class, 'card_shortcode' ] );

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

		self::register_patterns();
		if ( is_admin() ) {
			self::register_invitation_pattern();
		}
		add_action( 'rest_api_init', [ self::class, 'register_invitation_pattern' ] );
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
	 * Renders the board block in its compact form for the list shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: count, board (slug or term ID, every board
	 *                                           without it), sort (top or new) and status (a status slug, or all).
	 */
	public static function list_shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'count'  => '5',
				'board'  => '',
				'sort'   => '',
				'status' => '',
			],
			is_array( $atts ) ? $atts : [],
			self::LIST_SHORTCODE
		);

		return self::render(
			Board::BLOCK,
			[
				'compact' => true,
				'board'   => self::board_id( $atts['board'] ),
				'perPage' => absint( $atts['count'] ),
				'sort'    => $atts['sort'],
				'status'  => $atts['status'],
			]
		);
	}

	/**
	 * Renders the card block for the card shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: id, the request's ID.
	 */
	public static function card_shortcode( $atts ): string {
		$atts = shortcode_atts(
			[ 'id' => '' ],
			is_array( $atts ) ? $atts : [],
			self::CARD_SHORTCODE
		);

		return self::render( RequestCard::BLOCK, [ 'requestId' => absint( $atts['id'] ) ] );
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
	 * Registers the patterns whose content is always the same.
	 */
	private static function register_patterns(): void {
		register_block_pattern_category(
			self::PATTERN_CATEGORY,
			[ 'label' => __( 'Feature requests', 'feature-request-system' ) ]
		);

		$form   = '<!-- wp:' . SubmitForm::BLOCK . ' /-->';
		$board  = '<!-- wp:' . Board::BLOCK . ' /-->';
		$browse = self::heading( __( 'Browse requests', 'feature-request-system' ) );
		$top    = '<!-- wp:' . Board::BLOCK . ' {"compact":true,"perPage":5} /-->';

		self::register_pattern(
			self::PATTERN,
			__( 'Feature request page', 'feature-request-system' ),
			__( 'A form for suggesting features, above the board where people vote.', 'feature-request-system' ),
			implode( "\n\n", [ $form, $browse, $board ] )
		);

		self::register_pattern(
			self::PATTERN_COLUMNS,
			__( 'Feature request form beside the board', 'feature-request-system' ),
			__( 'A form for suggesting features in a narrow column, beside the board where people vote.', 'feature-request-system' ),
			'<!-- wp:columns {"align":"wide"} -->' . "\n"
			. '<div class="wp-block-columns alignwide"><!-- wp:column {"width":"36%"} -->' . "\n"
			. '<div class="wp-block-column" style="flex-basis:36%">' . $form . '</div>' . "\n"
			. '<!-- /wp:column -->' . "\n\n"
			. '<!-- wp:column {"width":"64%"} -->' . "\n"
			. '<div class="wp-block-column" style="flex-basis:64%">' . $browse . "\n\n" . $board . '</div>' . "\n"
			. '<!-- /wp:column --></div>' . "\n"
			. '<!-- /wp:columns -->'
		);

		self::register_pattern(
			self::PATTERN_MOST_WANTED,
			__( 'Most wanted feature requests', 'feature-request-system' ),
			__( 'The five feature requests with the most votes, with their vote buttons and a link to the board.', 'feature-request-system' ),
			self::group( self::heading( __( 'Most wanted', 'feature-request-system' ) ) . "\n\n" . $top )
		);
	}

	/**
	 * Registers the pattern that invites people to the board: a few words and a button.
	 *
	 * The button leads to the page that shows the default board, and finding that page
	 * takes the database. So the pattern is registered only where patterns are listed: in
	 * the admin and for the REST API, which the editor asks for them.
	 */
	public static function register_invitation_pattern(): void {
		$url = BoardSettings::page_url( RequestTerms::default_term( BoardTaxonomy::NAME ) );
		// Until a page shows the board, the button has no link, and whoever inserts the pattern gives it one.
		$href = '' === $url ? '' : ' href="' . esc_url( $url ) . '"';

		$text = '<!-- wp:paragraph -->' . "\n"
			. '<p>' . esc_html__( 'Tell us what you are missing. See what others have asked for, and vote for what matters to you.', 'feature-request-system' ) . '</p>' . "\n"
			. '<!-- /wp:paragraph -->';

		$button = '<!-- wp:buttons -->' . "\n"
			. '<div class="wp-block-buttons"><!-- wp:button -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>' . esc_html__( 'Suggest a feature', 'feature-request-system' ) . '</a></div>' . "\n"
			. '<!-- /wp:button --></div>' . "\n"
			. '<!-- /wp:buttons -->';

		self::register_pattern(
			self::PATTERN_INVITATION,
			__( 'Suggest a feature', 'feature-request-system' ),
			__( 'A few words that invite people to suggest features and vote, with a button that leads to the board.', 'feature-request-system' ),
			self::group( implode( "\n\n", [ self::heading( __( 'Have an idea?', 'feature-request-system' ) ), $text, $button ] ) ),
			[ 'call-to-action' ]
		);
	}

	/**
	 * Registers one pattern in the plugin's category.
	 *
	 * @param string   $name        Pattern name.
	 * @param string   $title       Title in the inserter.
	 * @param string   $description What the pattern is for.
	 * @param string   $content     Block markup.
	 * @param string[] $categories  Categories of WordPress to list the pattern in, besides the plugin's own.
	 */
	private static function register_pattern( string $name, string $title, string $description, string $content, array $categories = [] ): void {
		register_block_pattern(
			$name,
			[
				'title'       => $title,
				'description' => $description,
				'categories'  => array_merge( [ self::PATTERN_CATEGORY ], $categories ),
				'keywords'    => [ 'feature request', 'roadmap', 'feedback', 'ideas', 'vote' ],
				'content'     => $content,
			]
		);
	}

	/**
	 * Markup of a heading block.
	 *
	 * @param string $text Heading text.
	 */
	private static function heading( string $text ): string {
		return '<!-- wp:heading -->' . "\n"
			. '<h2 class="wp-block-heading">' . esc_html( $text ) . '</h2>' . "\n"
			. '<!-- /wp:heading -->';
	}

	/**
	 * Markup of a group block, which lets a site give the section a background or more space.
	 *
	 * @param string $content Markup of the blocks inside.
	 */
	private static function group( string $content ): string {
		return '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
			. '<div class="wp-block-group">' . $content . '</div>' . "\n"
			. '<!-- /wp:group -->';
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
	 * Finds a board from a shortcode attribute. 0 when there is no match: the default
	 * board for the board and the form, every board for a list.
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
