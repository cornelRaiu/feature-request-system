<?php
/**
 * Visitor state REST route.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use FeatureRequestSystem\Voting\VoteRepository;
use FeatureRequestSystem\Voting\VoterIdentity;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /mwtfrs/v1/me/state: which of the given requests the current visitor voted for.
 *
 * The board's HTML never contains per-visitor state, so it can be cached. The page asks
 * this route after it loads and marks the visitor's votes.
 */
final class StateController {

	/**
	 * Most request IDs one call may ask about.
	 */
	private const MAX_IDS = 100;

	/**
	 * Registers the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/me/state',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'state' ],
				// Anyone may ask; the answer only ever describes the caller's own votes.
				'permission_callback' => '__return_true',
				'args'                => [
					'ids' => [
						'description' => __( 'Request IDs shown on the page.', 'feature-request-system' ),
						'type'        => 'array',
						'items'       => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'maxItems'    => self::MAX_IDS,
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Returns the visitor's votes among the requested IDs.
	 *
	 * A guest without a voter cookie gets an empty list and no cookie.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function state( WP_REST_Request $request ): WP_REST_Response {
		$voter = VoterIdentity::key();
		$ids   = array_map( 'intval', (array) $request['ids'] );

		$response = new WP_REST_Response(
			[
				'voted' => '' === $voter ? [] : VoteRepository::voted_among( $voter, $ids ),
			]
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
