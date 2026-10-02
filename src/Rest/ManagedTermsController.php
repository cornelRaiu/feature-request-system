<?php
/**
 * REST controller for boards and statuses.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Terms_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's REST routes for boards and statuses, with one stricter rule.
 *
 * In a taxonomy without parents, WordPress lets everyone who may assign its terms also
 * add new ones, as with tags. Boards and statuses shape every request, so adding one over
 * the REST API takes what it takes on their admin screens: the capability to manage them.
 */
final class ManagedTermsController extends WP_REST_Terms_Controller {

	/**
	 * Checks whether the current user may add a term.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$taxonomy = get_taxonomy( $this->taxonomy );

		if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'You are not allowed to add boards or statuses.', 'feature-request-system' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return parent::create_item_permissions_check( $request );
	}
}
