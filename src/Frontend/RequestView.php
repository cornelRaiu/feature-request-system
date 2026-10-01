<?php
/**
 * The page of a single request.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Comments\CommentThread;
use FeatureRequestSystem\Comments\OfficialResponse;
use FeatureRequestSystem\Comments\OfficialResponseLink;
use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the vote button, status and categories above a request's description, and the
 * official response below it. The theme shows the title, the description and the comments.
 *
 * Block themes get a template for requests that holds the Feature Request Details block,
 * because their template for posts shows an author and post categories, which requests
 * do not have. WordPress lets plugins register templates since 6.7. Everywhere else, the
 * details are added to the request's content.
 */
final class RequestView {

	/**
	 * Name of the Feature Request Details block.
	 */
	public const BLOCK = 'mwtfrs/request';

	/**
	 * Name of the block template for a single request: this plugin's slug, then the template's.
	 */
	public const TEMPLATE = 'feature-request-system//single-' . RequestPostType::NAME;

	/**
	 * Priority on the_content: after WordPress has formatted the description, so the
	 * added markup is not turned into paragraphs.
	 */
	private const CONTENT_PRIORITY = 15;

	/**
	 * Whether the details and the response are being added right now.
	 *
	 * @var bool
	 */
	private static $adding = false;

	/**
	 * Adds the hook that completes a request's content.
	 */
	public static function add_hooks(): void {
		add_filter( 'the_content', [ self::class, 'add_to_content' ], self::CONTENT_PRIORITY );
	}

	/**
	 * Registers the block template for a single request. Runs on init.
	 */
	public static function register_template(): void {
		$file = Plugin::path( 'templates/single-request.html' );

		if ( function_exists( 'register_block_template' ) && is_readable( $file ) ) {
			register_block_template(
				self::TEMPLATE,
				[
					'title'       => __( 'Single Feature Request', 'feature-request-system' ),
					'description' => __( 'Shows one feature request: its vote button, status, description and comments.', 'feature-request-system' ),
					'content'     => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of this plugin.
				]
			);
		}
	}

	/**
	 * Adds the details above the description of the request being viewed, unless its
	 * template already has them, and the official response below.
	 *
	 * @param string $content Formatted post content.
	 */
	public static function add_to_content( string $content ): string {
		if ( self::$adding || ! self::is_request_page() ) {
			return $content;
		}

		self::$adding = true;
		$details      = self::template_has_details() ? '' : render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$response     = self::official_response( (int) get_the_ID() );
		self::$adding = false;

		return $details . $content . $response;
	}

	/**
	 * Renders the details of a request. Called by the block's render.php.
	 *
	 * @param int    $request_id         Request ID.
	 * @param string $wrapper_attributes Attributes from get_block_wrapper_attributes().
	 */
	public static function render_details( int $request_id, string $wrapper_attributes ): string {
		$post = get_post( $request_id );
		if ( ! $post || RequestPostType::NAME !== $post->post_type || post_password_required( $post ) ) {
			return '';
		}

		$board    = RequestTerms::term_of( $post->ID, BoardTaxonomy::NAME );
		$page_url = CurrentPage::url();

		VoteStore::provide();

		return Template::render(
			'request',
			[
				'namespace'          => VoteStore::NAME,
				'wrapper_attributes' => $wrapper_attributes,
				'context'            => [ 'ids' => [ $post->ID ] ],
				'item'               => RequestItem::prepare( $post, BoardSettings::voting( $board ? $board->term_id : 0 ), VoteForm::notice_from_request() ),
				'vote'               => VoteForm::template_args( $page_url ),
				'login_url'          => wp_login_url( $page_url ),
			]
		);
	}

	/**
	 * Whether the content being filtered is the description on a request's own page.
	 */
	private static function is_request_page(): bool {
		return is_singular( RequestPostType::NAME )
			&& in_the_loop()
			&& is_main_query()
			&& get_the_ID() === get_queried_object_id()
			&& ! is_feed()
			&& ! doing_filter( 'get_the_excerpt' )
			&& ! post_password_required();
	}

	/**
	 * Whether the block template in use already holds the Feature Request Details block.
	 */
	private static function template_has_details(): bool {
		global $_wp_current_template_content;

		return is_string( $_wp_current_template_content ) && has_block( self::BLOCK, $_wp_current_template_content );
	}

	/**
	 * Renders the request's newest official response, or '' when it has none.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function official_response( int $request_id ): string {
		$comment = OfficialResponse::latest( $request_id );
		if ( ! $comment ) {
			return '';
		}

		return Template::render(
			'official-response',
			[
				'id'         => 'mwtfrs-official-response-' . $request_id,
				'text'       => CommentThread::text_without_label( $comment ),
				'author'     => get_comment_author( $comment ),
				'date'       => get_comment_date( '', $comment ),
				'datetime'   => get_comment_date( 'c', $comment ),
				'url'        => get_comment_link( $comment ),
				// For moderators only, so every other visitor gets the same HTML.
				'remove_url' => OfficialResponse::can_mark( $comment, false )
					? OfficialResponseLink::url( (int) $comment->comment_ID, false, CurrentPage::url() )
					: '',
			]
		);
	}
}
