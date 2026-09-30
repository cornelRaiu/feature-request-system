<?php
/**
 * Plugin bootstrap.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the plugin's classes to WordPress hooks. Holds no logic of its own.
 */
final class Plugin {

	/**
	 * Plugin version. Must match the plugin header and the readme stable tag.
	 */
	public const VERSION = '0.1.0';

	/**
	 * Registers all hooks. Called once from the main plugin file.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	public static function boot( string $file ): void {
		// Services are wired in here as milestones add them.
	}
}
