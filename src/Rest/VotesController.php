<?php
/**
 * Vote REST routes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use FeatureRequestSystem\Security\RequestOrigin;
use FeatureRequestSystem\Voting\VoteService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST and DELETE /mwtfrs/v1/requests/{id}/vote.
 *
 * Both return {votes, voted}. Logged-in users authenticate with the standard wp_rest
 * nonce; without it WordPress treats them as guests.
 */
final class VotesController {

	/**
	 * A guest's vote did not come from a page on this site.
	 */
	public const ERROR_INVALID_ORIGIN = 'mwtfrs_invalid_origin';

	/**
	 * Registers the routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/requests/(?P<id>\d+)/vote',
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
					'callback'            => [ self::class, 'cast' ],
					'permission_callback' => [ self::class, 'can_vote' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'retract' ],
					'permission_callback' => [ self::class, 'can_vote' ],
				],
			]
		);
	}

	/**
	 * Permission check shared by both routes: the voting rules, plus a same-site check for guests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public static function can_vote( WP_REST_Request $request ) {
		$allowed = VoteService::check( (int) $request['id'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! is_user_logged_in() && ! RequestOrigin::is_same_site( (string) $request->get_header( 'origin' ), (string) $request->get_header( 'referer' ) ) ) {
			return new WP_Error(
				self::ERROR_INVALID_ORIGIN,
				__( 'Votes from visitors who are not logged in must be sent from this site.', 'feature-request-system' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Casts the current visitor's vote.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function cast( WP_REST_Request $request ): WP_REST_Response {
		return Response::from( VoteService::cast( (int) $request['id'] ) );
	}

	/**
	 * Removes the current visitor's vote.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function retract( WP_REST_Request $request ): WP_REST_Response {
		return Response::from( VoteService::retract( (int) $request['id'] ) );
	}
}
