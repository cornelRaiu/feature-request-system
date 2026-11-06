<?php
/**
 * Merge REST route.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use FeatureRequestSystem\Merging\MergeService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /mwtfrs/v1/requests/{id}/merge {target}: merges a request into another.
 *
 * Returns {id, merged_into, votes, url}: the merged request, the request it now lives on,
 * that request's vote count and its address. Only moderators may merge, and they
 * authenticate with the standard wp_rest nonce.
 */
final class MergeController {

	/**
	 * Registers the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/requests/(?P<id>\d+)/merge',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'merge' ],
				'permission_callback' => [ self::class, 'can_merge' ],
				'args'                => [
					'id'     => [
						'description' => __( 'ID of the request to merge.', 'feature-request-system' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					],
					'target' => [
						'description' => __( 'ID of the request to merge it into.', 'feature-request-system' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Permission check: the user may merge requests.
	 *
	 * @return true|WP_Error
	 */
	public static function can_merge() {
		if ( MergeService::can_merge() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to merge requests.', 'feature-request-system' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Merges the request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function merge( WP_REST_Request $request ): WP_REST_Response {
		return Response::from( MergeService::merge( (int) $request['id'], (int) $request['target'] ) );
	}
}
