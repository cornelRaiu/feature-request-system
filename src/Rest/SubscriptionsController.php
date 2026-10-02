<?php
/**
 * Follow REST routes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use FeatureRequestSystem\Notifications\Subscriptions;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST and DELETE /mwtfrs/v1/requests/{id}/subscription: follow a request, or stop.
 *
 * Both return {following}. Only logged-in users can follow, and they authenticate with
 * the standard wp_rest nonce, so anyone else gets a 401 from the rules in Subscriptions.
 */
final class SubscriptionsController {

	/**
	 * Registers the routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/requests/(?P<id>\d+)/subscription',
			[
				'args' => [
					'id' => [
						'description' => __( 'Request ID.', 'feature-request-system' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'follow' ],
					// The rules are checked in the callback, which answers anyone with what applies to them.
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'unfollow' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * Makes the current user follow a request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function follow( WP_REST_Request $request ): WP_REST_Response {
		return Response::from( Subscriptions::follow( (int) $request['id'] ) );
	}

	/**
	 * Makes the current user stop following a request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function unfollow( WP_REST_Request $request ): WP_REST_Response {
		return Response::from( Subscriptions::unfollow( (int) $request['id'] ) );
	}
}
