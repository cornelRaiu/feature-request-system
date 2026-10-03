<?php
/**
 * State for the voting script.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Rest\RestApi;
use stdClass;

defined( 'ABSPATH' ) || exit;

/**
 * The Interactivity API store that vote buttons use, wherever requests are shown, and the
 * Follow button on a request's page.
 */
final class VoteStore {

	/**
	 * Store namespace, as in data-wp-interactive.
	 */
	public const NAME = 'mwtfrs/votes';

	/**
	 * Shares the settings the vote script needs. Holds nothing about the visitor except,
	 * for logged-in users, the REST nonce WordPress requires.
	 */
	public static function provide(): void {
		wp_interactivity_state(
			self::NAME,
			[
				'restUrl' => rest_url( RestApi::NAMESPACE . '/' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'voted'   => new stdClass(),
				// Counts that changed on this page, so that a request shown twice shows one number.
				'votes'   => new stdClass(),
				'strings' => [
					'added'       => __( 'Vote added.', 'feature-request-system' ),
					'removed'     => __( 'Vote removed.', 'feature-request-system' ),
					'error'       => __( 'Your vote could not be saved. Check your connection and try again.', 'feature-request-system' ),
					'following'   => __( 'You now follow this request.', 'feature-request-system' ),
					'unfollowed'  => __( 'You no longer follow this request.', 'feature-request-system' ),
					'followError' => __( 'That could not be saved. Check your connection and try again.', 'feature-request-system' ),
				],
			]
		);
	}

	/**
	 * The context of a block that shows requests: the board, a list, a card or a request's page.
	 *
	 * @param array<int, array<string, mixed>> $items Requests from RequestItem::prepare().
	 * @return array{ids: int[], guestVotes: bool, announcement: string}
	 */
	public static function context( array $items ): array {
		return [
			'ids'          => array_values( wp_list_pluck( $items, 'id' ) ),
			// Visitors who are not logged in can have votes only where a board lets guests vote.
			// Elsewhere the script does not ask for them, and the page costs the server nothing more.
			'guestVotes'   => in_array( true, wp_list_pluck( $items, 'guest_votes' ), true ),
			// What the block says to screen readers after a vote. Each block has its own, so a
			// page with several of them says it once.
			'announcement' => '',
		];
	}
}
