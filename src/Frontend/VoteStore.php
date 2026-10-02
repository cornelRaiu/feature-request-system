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
 * The Interactivity API store that vote buttons use, on the board and on a request's page,
 * and the Follow button on a request's page.
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
				'restUrl'      => rest_url( RestApi::NAMESPACE . '/' ),
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'voted'        => new stdClass(),
				'announcement' => '',
				'strings'      => [
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
}
