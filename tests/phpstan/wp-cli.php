<?php
/**
 * The parts of WP-CLI that the plugin calls, so PHPStan can check them.
 *
 * The php-stubs/wp-cli-stubs package does not yet allow the WordPress 7 stubs.
 *
 * @package FeatureRequestSystem
 */

/**
 * WP-CLI entry point.
 */
class WP_CLI {

	/**
	 * Registers a command.
	 *
	 * @param string              $name     Command name.
	 * @param callable|string     $callable Command implementation: a class name, callable or closure.
	 * @param array<string,mixed> $args     Registration options.
	 */
	public static function add_command( $name, $callable, $args = [] ): bool {
		return true;
	}

	/**
	 * Prints a success message.
	 *
	 * @param string $message Message to print.
	 */
	public static function success( $message ): void {
	}
}
