<?php
/**
 * Request submission REST routes.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use FeatureRequestSystem\Security\RequestOrigin;
use FeatureRequestSystem\Submission\SimilarRequests;
use FeatureRequestSystem\Submission\SpamGuard;
use FeatureRequestSystem\Submission\SubmissionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /mwtfrs/v1/boards/{board}/requests submits a request.
 * GET /mwtfrs/v1/boards/{board}/similar?q= suggests existing requests while someone types.
 */
final class RequestsController {

	/**
	 * Registers the routes.
	 */
	public static function register_routes(): void {
		$board = [
			'description' => __( 'Board ID.', 'feature-request-system' ),
			'type'        => 'integer',
			'minimum'     => 1,
			'required'    => true,
		];

		register_rest_route(
			RestApi::NAMESPACE,
			'/boards/(?P<board>\d+)/requests',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'submit' ],
				'permission_callback' => [ self::class, 'can_submit' ],
				'args'                => [
					'board'                => $board,
					'title'                => [
						'description' => __( 'Request title.', 'feature-request-system' ),
						'type'        => 'string',
						'required'    => true,
					],
					'description'          => [
						'description' => __( 'Details of the request.', 'feature-request-system' ),
						'type'        => 'string',
					],
					'category'             => [
						'description' => __( 'Category ID.', 'feature-request-system' ),
						'type'        => [ 'integer', 'string' ],
					],
					'name'                 => [
						'description' => __( 'Name of a visitor who is not logged in.', 'feature-request-system' ),
						'type'        => 'string',
					],
					'email'                => [
						'description' => __( 'Email of a visitor who is not logged in. Never shown publicly.', 'feature-request-system' ),
						'type'        => 'string',
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

		register_rest_route(
			RestApi::NAMESPACE,
			'/boards/(?P<board>\d+)/similar',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'similar' ],
				// Only published requests, which anyone can already see on the board.
				'permission_callback' => '__return_true',
				'args'                => [
					'board' => $board,
					'q'     => [
						'description' => __( 'What the visitor is typing.', 'feature-request-system' ),
						'type'        => 'string',
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Permission check: the board's rules, plus a same-site check for visitors who are not logged in.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public static function can_submit( WP_REST_Request $request ) {
		$allowed = SubmissionService::check( (int) $request['board'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! is_user_logged_in() && ! RequestOrigin::is_same_site( (string) $request->get_header( 'origin' ), (string) $request->get_header( 'referer' ) ) ) {
			return new WP_Error(
				VotesController::ERROR_INVALID_ORIGIN,
				__( 'Requests from visitors who are not logged in must be sent from this site.', 'feature-request-system' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Submits a request. Responds with its ID, its status (pending or publish) and a message to show.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function submit( WP_REST_Request $request ): WP_REST_Response {
		$result = SubmissionService::submit( (int) $request['board'], $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return Response::from( $result );
		}

		$result['message'] = 'publish' === $result['status']
			? __( 'Thanks! Your request is on the board.', 'feature-request-system' )
			: __( 'Thanks! Your request will appear on the board once it is approved.', 'feature-request-system' );

		return Response::from( $result, 201 );
	}

	/**
	 * Returns published requests on the board that match the text.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function similar( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( SimilarRequests::find( (int) $request['board'], (string) $request['q'] ) );
	}
}
