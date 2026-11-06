<?php
/**
 * Merge box on the edit screen.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Merging\MergedStatus;
use FeatureRequestSystem\Merging\MergeService;
use FeatureRequestSystem\Plugin;
use FeatureRequestSystem\Submission\SimilarRequests;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A "Merge into…" box on a request's edit screen, for moderators.
 *
 * It offers the published requests of the same board whose titles are like this one's,
 * and with JavaScript a field that finds any of them by title. Merging cannot be undone,
 * so the box says so and asks to confirm with a checkbox.
 *
 * The box sits inside the edit screen's form, which cannot hold a form of its own. Its
 * button sends that form to admin-post.php instead, where only the box's own fields count.
 */
final class MergeBox {

	/**
	 * The admin-post action, also the start of the nonce action.
	 */
	public const ACTION = 'mwtfrs_merge_request';

	/**
	 * Name of the field with the ID of the request to merge.
	 */
	public const FIELD_REQUEST = 'mwtfrs_merge_id';

	/**
	 * Name of the radio buttons that choose the request to merge into.
	 */
	public const FIELD_TARGET = 'mwtfrs_merge_target';

	/**
	 * Name of the checkbox that confirms the merge.
	 */
	public const FIELD_CONFIRM = 'mwtfrs_merge_confirm';

	/**
	 * Name of the nonce field.
	 */
	public const NONCE_FIELD = 'mwtfrs_merge_nonce';

	/**
	 * Query argument of the edit screen after a merge: the ID of the request merged into the one shown.
	 */
	public const ARG_MERGED = 'mwtfrs_merged_from';

	/**
	 * Query argument of the edit screen after a refused merge: why it was refused.
	 */
	public const ARG_ERROR = 'mwtfrs_merge_error';

	/**
	 * HTML id of the box.
	 */
	private const ID = 'mwtfrs-merge';

	/**
	 * HTML id of WordPress's Publish box.
	 */
	private const PUBLISH_BOX = 'submitdiv';

	/**
	 * Handle of the script that finds requests by title.
	 */
	private const SCRIPT = 'mwtfrs-merge-box';

	/**
	 * Most requests the search shows.
	 */
	private const SEARCH_LIMIT = 10;

	/**
	 * Adds the box, the form handler, the notices and the script.
	 */
	public static function add_hooks(): void {
		add_action( 'add_meta_boxes_' . RequestPostType::NAME, [ self::class, 'add' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_notices', [ self::class, 'render_notice' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_script' ] );
	}

	/**
	 * Registers the box for moderators, on requests that exist.
	 *
	 * A merged request stays merged whatever saves it, so its Publish box would only
	 * mislead: "Publish" and "Save Draft" would change nothing and then say they had. On
	 * its edit screen this box takes that one's place, with the link to the trash.
	 *
	 * @param WP_Post $post Request being edited.
	 */
	public static function add( WP_Post $post ): void {
		if ( MergedStatus::NAME === $post->post_status ) {
			remove_meta_box( self::PUBLISH_BOX, RequestPostType::NAME, 'side' );
			add_meta_box( self::ID, _x( 'Merged', 'post status of a request merged into another', 'feature-request-system' ), [ self::class, 'render' ], RequestPostType::NAME, 'side', 'high' );

			return;
		}
		if ( ! MergeService::can_merge() || in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}

		add_meta_box( self::ID, __( 'Merge into…', 'feature-request-system' ), [ self::class, 'render' ], RequestPostType::NAME, 'side', 'low' );
	}

	/**
	 * Prints the box: where a merged request went, or the choices for merging this one.
	 *
	 * @param WP_Post $post Request being edited.
	 */
	public static function render( WP_Post $post ): void {
		if ( MergedStatus::NAME === $post->post_status ) {
			$target = MergedStatus::into( $post->ID );
			$link   = (string) get_edit_post_link( $target );
			$title  = esc_html( get_the_title( $target ) );

			printf(
				'<p>%1$s</p><p>%2$s</p>',
				/* translators: %s: title of the request this one was merged into, linked to its edit screen. */
				sprintf( esc_html__( 'This request was merged into %s. Its address leads there.', 'feature-request-system' ), '' !== $link ? '<a href="' . esc_url( $link ) . '">' . $title . '</a>' : $title ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				esc_html__( 'Merging cannot be undone.', 'feature-request-system' )
			);
			if ( current_user_can( 'delete_post', $post->ID ) ) {
				printf( '<p><a class="submitdelete" href="%1$s">%2$s</a></p>', esc_url( (string) get_delete_post_link( $post->ID ) ), esc_html__( 'Move to Trash', 'feature-request-system' ) );
			}

			return;
		}

		$board = RequestTerms::term_of( $post->ID, BoardTaxonomy::NAME );

		echo '<p>' . esc_html__( 'Merge this request into another one on its board that asks for the same. Its votes and followers move there, and its address leads there. Its comments stay with it.', 'feature-request-system' ) . '</p>';

		printf( '<fieldset><legend>%s</legend>', esc_html__( 'Request to merge into', 'feature-request-system' ) );
		echo '<div id="' . esc_attr( self::ID . '-choices' ) . '">';
		self::render_choices( $post, $board ? $board->term_id : 0 );
		echo '</div></fieldset>';

		printf(
			'<p class="hide-if-no-js"><label for="%1$s">%2$s</label><input type="search" class="widefat" id="%1$s" autocomplete="off" data-path="%3$s" data-field="%4$s"></p><p id="%5$s" class="description" role="status"></p>',
			esc_attr( self::ID . '-search' ),
			esc_html__( 'Find another request by its title', 'feature-request-system' ),
			esc_attr( self::search_path( $post->ID, $board ? $board->term_id : 0 ) ),
			esc_attr( self::FIELD_TARGET ),
			esc_attr( self::ID . '-status' )
		);

		printf(
			'<p><input type="hidden" name="%1$s" value="%2$d"><label><input type="checkbox" name="%3$s" value="1"> %4$s</label></p>',
			esc_attr( self::FIELD_REQUEST ),
			(int) $post->ID,
			esc_attr( self::FIELD_CONFIRM ),
			esc_html__( 'Merging cannot be undone. I want to merge this request.', 'feature-request-system' )
		);
		wp_nonce_field( self::ACTION . '-' . $post->ID, self::NONCE_FIELD, false );

		// The edit screen's form already sends action=editpost. This button's value comes later in the form, so it wins.
		printf(
			'<p><button type="submit" class="button" name="action" value="%1$s" formaction="%2$s">%3$s</button></p>',
			esc_attr( self::ACTION ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Merge', 'feature-request-system' )
		);
	}

	/**
	 * Handles the box's form post and redirects to the edit screen of the request that
	 * the votes went to, or back to this one with what went wrong.
	 */
	public static function handle(): void {
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() checks the nonce.

		wp_safe_redirect( self::process( $input ) );
		exit;
	}

	/**
	 * Merges the request the form names, and returns the URL to go to.
	 *
	 * @param array<string, mixed> $input Posted fields: the box's fields and its nonce.
	 */
	public static function process( array $input ): string {
		$request_id = absint( $input[ self::FIELD_REQUEST ] ?? 0 );
		$target_id  = absint( $input[ self::FIELD_TARGET ] ?? 0 );
		$nonce      = sanitize_text_field( (string) ( $input[ self::NONCE_FIELD ] ?? '' ) );

		if ( ! wp_verify_nonce( $nonce, self::ACTION . '-' . $request_id ) ) {
			$error = 'expired';
		} elseif ( ! MergeService::can_merge() ) {
			$error = 'denied';
		} elseif ( ! $target_id ) {
			$error = 'target';
		} elseif ( empty( $input[ self::FIELD_CONFIRM ] ) ) {
			$error = 'confirm';
		} else {
			$result = MergeService::merge( $request_id, $target_id );
			if ( ! is_wp_error( $result ) ) {
				return add_query_arg( self::ARG_MERGED, $request_id, self::edit_url( $target_id ) );
			}
			$error = (string) $result->get_error_code();
		}

		return add_query_arg( self::ARG_ERROR, rawurlencode( $error ), self::edit_url( $request_id ) );
	}

	/**
	 * Prints what the last merge did, on the edit screen it led to.
	 */
	public static function render_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || RequestPostType::NAME !== $screen->post_type ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only says what happened; changes nothing.
		$merged = isset( $_GET[ self::ARG_MERGED ] ) ? absint( $_GET[ self::ARG_MERGED ] ) : 0;
		$error  = isset( $_GET[ self::ARG_ERROR ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_ERROR ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $merged && MergedStatus::NAME === get_post_status( $merged ) ) {
			wp_admin_notice(
				/* translators: %s: title of the request that was merged into this one. */
				esc_html( sprintf( __( '"%s" was merged into this request. Its votes and followers are here now, and its address leads here.', 'feature-request-system' ), get_the_title( $merged ) ) ),
				[
					'type'        => 'success',
					'dismissible' => true,
				]
			);
		}

		$message = '' !== $error ? self::error_message( $error ) : '';
		if ( '' !== $message ) {
			wp_admin_notice(
				esc_html( $message ),
				[
					'type'        => 'error',
					'dismissible' => true,
				]
			);
		}
	}

	/**
	 * Loads the script that finds requests by title, on the edit screen of a request.
	 *
	 * @param string $screen File name of the admin screen.
	 */
	public static function enqueue_script( string $screen ): void {
		$current = get_current_screen();
		if ( 'post.php' !== $screen || ! $current || RequestPostType::NAME !== $current->post_type || ! MergeService::can_merge() ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT, Plugin::url( 'assets/admin/merge-box.js' ), [ 'wp-api-fetch', 'wp-i18n' ], Plugin::VERSION, true );
		wp_set_script_translations( self::SCRIPT, 'feature-request-system', Plugin::path( 'languages' ) );
	}

	/**
	 * Prints the radio buttons for the published requests of the board whose titles are
	 * like this one's. The script replaces them with what a search finds.
	 *
	 * @param WP_Post $post     Request being edited.
	 * @param int     $board_id Its board's term ID.
	 */
	private static function render_choices( WP_Post $post, int $board_id ): void {
		$choices = array_filter(
			SimilarRequests::find( $board_id, $post->post_title ),
			static function ( array $similar ) use ( $post ): bool {
				return $similar['id'] !== $post->ID;
			}
		);

		if ( ! $choices ) {
			echo '<p>' . esc_html__( 'No request on this board has a similar title.', 'feature-request-system' ) . '</p>';

			return;
		}

		echo '<ul>';
		foreach ( $choices as $choice ) {
			printf(
				'<li><label><input type="radio" name="%1$s" value="%2$d"> %3$s <span class="description">(%4$s)</span></label></li>',
				esc_attr( self::FIELD_TARGET ),
				(int) $choice['id'],
				esc_html( $choice['title'] ),
				esc_html( $choice['label'] )
			);
		}
		echo '</ul>';
	}

	/**
	 * The REST path that lists the board's other published requests, for the search. The
	 * script adds the text to search for.
	 *
	 * @param int $request_id Request being edited, which the search leaves out.
	 * @param int $board_id   Its board's term ID, or 0.
	 */
	private static function search_path( int $request_id, int $board_id ): string {
		$board = get_taxonomy( BoardTaxonomy::NAME );
		$args  = [
			'status'   => 'publish',
			'context'  => 'edit',
			'per_page' => self::SEARCH_LIMIT,
			'_fields'  => 'id,title',
			'exclude'  => $request_id, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A query argument of core's REST endpoint for posts, which leaves out the request being edited.
		];
		if ( $board_id && $board ) {
			$args[ $board->rest_base ? $board->rest_base : $board->name ] = $board_id;
		}

		return add_query_arg( $args, rest_get_route_for_post_type_items( RequestPostType::NAME ) );
	}

	/**
	 * Says why a merge from the box was refused, or '' for a code it does not know.
	 *
	 * @param string $code Refusal code from the URL.
	 */
	private static function error_message( string $code ): string {
		switch ( $code ) {
			case 'expired':
				return __( 'The form had expired, so nothing was merged. Please try again.', 'feature-request-system' );
			case 'denied':
				return __( 'You are not allowed to merge requests.', 'feature-request-system' );
			case 'target':
				return __( 'Choose the request to merge this one into.', 'feature-request-system' );
			case 'confirm':
				return __( 'Nothing was merged. Tick the box to confirm, because merging cannot be undone.', 'feature-request-system' );
			default:
				return MergeService::message( $code );
		}
	}

	/**
	 * Edit screen of a request.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function edit_url( int $request_id ): string {
		return $request_id ? admin_url( 'post.php?post=' . $request_id . '&action=edit' ) : admin_url( 'edit.php?post_type=' . RequestPostType::NAME );
	}
}
