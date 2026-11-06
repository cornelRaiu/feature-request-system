<?php
/**
 * Guest email REST route.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use FeatureRequestSystem\Guests\Guests;
use FeatureRequestSystem\Security\RequestOrigin;
use FeatureRequestSystem\Submission\SpamGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /mwtfrs/v1/guests/link: emails a visitor the link that confirms their address,
 * and keeps their vote until they press the button on the link's page.
 *
 * Returns {message}, the same whatever is known about the address.
 */
final class GuestsController {

	/**
	 * Registers the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			RestApi::NAMESPACE,
			'/guests/link',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'link' ],
				'permission_callback' => [ self::class, 'can_ask' ],
				'args'                => [
					'email'                => [
						'description' => __( 'Email address of the visitor. Never shown publicly.', 'feature-request-system' ),
						'type'        => 'string',
						'required'    => true,
					],
					'request'              => [
						'description' => __( 'ID of the request the visitor votes for.', 'feature-request-system' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					],
					SpamGuard::FIELD_TRAP  => [
						'description' => __( 'Must be left empty.', 'feature-request-system' ),
						'type'        => 'string',
					],
					SpamGuard::FIELD_TOKEN => [
						'description' => __( 'Token from the rendered form.', 'feature-request-system' ),
						'type'        => 'string',
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Permission check: the request must come from a page on this site.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public static function can_ask( WP_REST_Request $request ) {
		// The Origin header only, never the Referer: RequestOrigin says why.
		if ( ! is_user_logged_in() && ! RequestOrigin::is_same_site( (string) $request->get_header( 'origin' ) ) ) {
			return new WP_Error(
				VotesController::ERROR_INVALID_ORIGIN,
				__( 'Requests from visitors who are not logged in must be sent from this site.', 'feature-request-system' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Emails the link, unless one went to the address minutes ago, and keeps the vote.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function link( WP_REST_Request $request ): WP_REST_Response {
		$result = Guests::start_vote( (string) $request['email'], (int) $request['request'], $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return Response::from( $result );
		}

		return Response::from( [ 'message' => __( 'Check your email. Open the link we sent you within 24 hours and press the button on its page: then your vote counts.', 'feature-request-system' ) ] );
	}
}
