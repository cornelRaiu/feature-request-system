<?php
/**
 * Template loading.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the plugin's PHP templates, letting a theme override any of them.
 *
 * A theme overrides templates/board.php by providing feature-request-system/board.php.
 */
final class Template {

	/**
	 * Folder in a theme that holds template overrides.
	 */
	private const THEME_FOLDER = 'feature-request-system';

	/**
	 * Renders a template and returns its output.
	 *
	 * @param string               $name Template name without extension, such as "board".
	 * @param array<string, mixed> $args Data for the template, available to it as $args.
	 */
	public static function render( string $name, array $args ): string {
		$file = locate_template( self::THEME_FOLDER . '/' . $name . '.php' );
		if ( '' === $file ) {
			$file = Plugin::path( 'templates/' . $name . '.php' );
		}

		ob_start();
		load_template( $file, false, $args );

		return (string) ob_get_clean();
	}
}
