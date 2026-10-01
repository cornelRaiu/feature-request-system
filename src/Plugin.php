<?php
/**
 * Plugin bootstrap.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem;

use FeatureRequestSystem\Admin\BoardFields;
use FeatureRequestSystem\Admin\CommentActions;
use FeatureRequestSystem\Admin\PendingBadge;
use FeatureRequestSystem\Admin\RequestDetailsBox;
use FeatureRequestSystem\Admin\RequestList;
use FeatureRequestSystem\Admin\SettingsPage;
use FeatureRequestSystem\Admin\StatusFields;
use FeatureRequestSystem\Admin\StatusQuickEdit;
use FeatureRequestSystem\Admin\TermSelectBox;
use FeatureRequestSystem\Cli\Commands;
use FeatureRequestSystem\Comments\CommentForm;
use FeatureRequestSystem\Comments\CommentThread;
use FeatureRequestSystem\Comments\OfficialResponseLink;
use FeatureRequestSystem\Content\ContentTypes;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusChange;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Frontend\Blocks;
use FeatureRequestSystem\Frontend\RequestView;
use FeatureRequestSystem\Frontend\SubmitForm;
use FeatureRequestSystem\Frontend\VoteForm;
use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Notifications\NewRequestEmail;
use FeatureRequestSystem\Rest\RestApi;
use FeatureRequestSystem\Submission\Akismet;
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
		add_action( 'init', [ RequestView::class, 'register_template' ] );

		RequestPostType::add_hooks();
		RequestTerms::add_hooks();
		StatusChange::add_hooks();
		StatusTaxonomy::add_hooks();
		VoteService::add_hooks();
		VoteForm::add_hooks();
		SubmitForm::add_hooks();
		Akismet::add_hooks();
		NewRequestEmail::add_hooks();
		RequestView::add_hooks();
		CommentForm::add_hooks();
		CommentThread::add_hooks();
		OfficialResponseLink::add_hooks();
		CommentActions::add_hooks();
		RequestList::add_hooks();
		StatusQuickEdit::add_hooks();
		PendingBadge::add_hooks();
		RequestDetailsBox::add_hooks();
		TermSelectBox::add_hooks();
		SettingsPage::add_hooks();
		BoardFields::add_hooks();
		StatusFields::add_hooks();

		add_action( 'rest_api_init', [ RestApi::class, 'register_routes' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( Commands::NAME, Commands::class );
		}
	}

	/**
	 * Returns the URL of a file inside the plugin folder.
	 *
	 * @param string $relative Path relative to the plugin folder, such as "assets/admin/quick-edit.js".
	 */
	public static function url( string $relative ): string {
		return plugins_url( $relative, self::$file );
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
