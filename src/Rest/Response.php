<?php
/**
 * REST responses from service results.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what a service returns into a REST response.
 */
final class Response {

	/**
	 * Builds a response from data or an error. Rate-limit errors get a Retry-After header.
	 *
	 * @param array<string, mixed>|WP_Error $result Service result.
	 * @param int                           $status HTTP status for success.
	 */
	public static function from( $result, int $status = 200 ): WP_REST_Response {
		if ( ! is_wp_error( $result ) ) {
			return new WP_REST_Response( $result, $status );
		}

		$response = rest_convert_error_to_response( $result );
		$data     = $result->get_error_data();
		if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) $data['retry_after'] );
		}

		return $response;
	}
}
