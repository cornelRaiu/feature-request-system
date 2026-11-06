<?php
/**
 * Stand-in for WP-CLI in tests.
 *
 * @package FeatureRequestSystem
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Mirrors WP-CLI's own class name.

/**
 * Keeps what a command prints, the way WP-CLI would print it.
 */
class WP_CLI {

	/**
	 * Lines printed so far.
	 *
	 * @var string[]
	 */
	public static $lines = [];

	/**
	 * Prints a success message.
	 *
	 * @param string $message Message to print.
	 */
	public static function success( $message ): void {
		self::$lines[] = 'Success: ' . $message;
	}

	/**
	 * Prints a line.
	 *
	 * @param string $message Message to print.
	 */
	public static function log( $message ): void {
		self::$lines[] = $message;
	}

	/**
	 * Prints an error. WP-CLI then ends the command, which here becomes an exception.
	 *
	 * @param string $message Message to print.
	 * @throws RuntimeException Always.
	 */
	public static function error( $message ): void {
		self::$lines[] = 'Error: ' . $message;
		throw new RuntimeException( $message );
	}
}
