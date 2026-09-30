<?php
/**
 * Plugin bootstrap.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem;

use FeatureRequestSystem\Cli\Commands;
use FeatureRequestSystem\Content\ContentTypes;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Voting\VoteService;
use WP_CLI;

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
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private static $file = '';

	/**
	 * Registers all hooks. Called once from the main plugin file.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	public static function boot( string $file ): void {
		self::$file = $file;

		register_activation_hook( $file, [ Installer::class, 'activate' ] );
		register_deactivation_hook( $file, [ Installer::class, 'deactivate' ] );

		add_action( 'init', [ ContentTypes::class, 'register' ] );
		// After content types are registered, because installing seeds terms.
		add_action( 'init', [ Installer::class, 'maybe_upgrade' ], 20 );
		add_action( 'init', [ Blocks::class, 'register' ] );

		RequestPostType::add_hooks();
		RequestTerms::add_hooks();
		VoteService::add_hooks();
		VoteForm::add_hooks();

		add_action( 'rest_api_init', [ RestApi::class, 'register_routes' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( Commands::NAME, Commands::class );
		}
	}

	/**
	 * Returns an absolute path inside the plugin folder.
	 *
	 * @param string $relative Path relative to the plugin folder, such as "templates/board.php".
	 */
	public static function path( string $relative = '' ): string {
		return plugin_dir_path( self::$file ) . ltrim( $relative, '/' );
	}
}
