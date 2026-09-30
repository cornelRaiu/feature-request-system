<?php
/**
 * REST API registration.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's REST routes under one namespace.
 */
final class RestApi {

	/**
	 * Namespace of every plugin route.
	 */
	public const NAMESPACE = 'mwtfrs/v1';

	/**
	 * Registers all routes. Runs on rest_api_init.
	 */
	public static function register_routes(): void {
		VotesController::register_routes();
	}
}
