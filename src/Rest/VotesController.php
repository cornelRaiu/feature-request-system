<?php
/**
 * Vote REST routes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use FeatureRequestSystem\Notifications\Subscriptions;
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
 * Both return {votes, voted}, and for logged-in users also {following}, because voting
 * can make a user follow the request. Logged-in users authenticate with the standard
 * wp_rest nonce; without it WordPress treats them as guests.
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
		return Response::from( self::with_following( VoteService::cast( (int) $request['id'] ), (int) $request['id'] ) );
	}

	/**
	 * Removes the current visitor's vote.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function retract( WP_REST_Request $request ): WP_REST_Response {
		return Response::from( self::with_following( VoteService::retract( (int) $request['id'] ), (int) $request['id'] ) );
	}

	/**
	 * Adds whether a logged-in user follows the request now, since a vote can change that.
	 *
	 * @param array<string, mixed>|WP_Error $result     Result of the vote.
	 * @param int                           $request_id Request ID.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function with_following( $result, int $request_id ) {
		if ( ! is_wp_error( $result ) && is_user_logged_in() ) {
			$result['following'] = Subscriptions::is_following( $request_id, get_current_user_id() );
		}

		return $result;
	}
}
