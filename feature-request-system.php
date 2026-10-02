<?php
/**
 * Plugin Name:       Feature Request System
 * Plugin URI:        https://www.cornelraiu.com/feature-request-system
 * Description:       Let users submit and vote on feature requests, share your roadmap, and tell every voter when you ship.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Cornel Raiu
 * Author URI:        https://www.cornelraiu.com/
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       feature-request-system
 *
 * @package FeatureRequestSystem
 */

defined( 'ABSPATH' ) || exit;

// Keep this file free of newer PHP syntax so the notice below can still show on old PHP versions.
if ( version_compare( PHP_VERSION, '7.4', '<' ) || version_compare( get_bloginfo( 'version' ), '6.6', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Feature Request System needs PHP 7.4 or later and WordPress 6.6 or later, so it is not running. Update PHP or WordPress to use it.', 'feature-request-system' )
				);
			}
		}
	);
	return;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) || ! is_readable( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Feature Request System is missing its vendor folder, so it is not running. Run composer install, or reinstall the plugin from WordPress.org.', 'feature-request-system' )
				);
			}
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Action Scheduler sends the emails to a request's followers in the background. Many plugins
// bring a copy. Each copy must be loaded before plugins_loaded, and the newest one then runs.
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

FeatureRequestSystem\Plugin::boot( __FILE__ );
