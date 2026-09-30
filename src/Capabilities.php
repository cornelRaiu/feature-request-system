<?php
/**
 * Plugin capabilities.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem;

use FeatureRequestSystem\Content\RequestPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the plugin's capabilities and grants them to roles.
 */
final class Capabilities {

	/**
	 * Lets a user change the plugin's settings.
	 */
	public const MANAGE_SETTINGS = 'manage_mwtfrs_settings';

	/**
	 * Primitive capabilities of the request post type. WordPress maps meta capabilities
	 * such as edit_post onto these because the post type uses map_meta_cap.
	 */
	public const REQUEST_CAPS = [
		'edit_' . RequestPostType::CAPABILITY_TYPE,
		'edit_others_' . RequestPostType::CAPABILITY_TYPE,
		'edit_private_' . RequestPostType::CAPABILITY_TYPE,
		'edit_published_' . RequestPostType::CAPABILITY_TYPE,
		'publish_' . RequestPostType::CAPABILITY_TYPE,
		'read_private_' . RequestPostType::CAPABILITY_TYPE,
		'delete_' . RequestPostType::CAPABILITY_TYPE,
		'delete_others_' . RequestPostType::CAPABILITY_TYPE,
		'delete_private_' . RequestPostType::CAPABILITY_TYPE,
		'delete_published_' . RequestPostType::CAPABILITY_TYPE,
	];

	/**
	 * Capabilities WordPress checks for boards, statuses and categories. Managing them
	 * requires editing other people's requests; assigning them requires editing requests.
	 *
	 * @return array<string, string>
	 */
	public static function term_caps(): array {
		return [
			'manage_terms' => 'edit_others_' . RequestPostType::CAPABILITY_TYPE,
			'edit_terms'   => 'edit_others_' . RequestPostType::CAPABILITY_TYPE,
			'delete_terms' => 'edit_others_' . RequestPostType::CAPABILITY_TYPE,
			'assign_terms' => 'edit_' . RequestPostType::CAPABILITY_TYPE,
		];
	}

	/**
	 * Gives administrators every plugin capability and editors everything except settings.
	 */
	public static function grant(): void {
		$grants = [
			'administrator' => array_merge( self::REQUEST_CAPS, [ self::MANAGE_SETTINGS ] ),
			'editor'        => self::REQUEST_CAPS,
		];

		foreach ( $grants as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes every plugin capability from every role.
	 */
	public static function revoke(): void {
		$caps = array_merge( self::REQUEST_CAPS, [ self::MANAGE_SETTINGS ] );

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $caps as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
