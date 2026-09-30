<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is removed only on sites where the "delete data on uninstall" setting is on.
 *
 * @package FeatureRequestSystem
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

if ( ! is_multisite() ) {
	FeatureRequestSystem\Install\Uninstaller::run();
	return;
}

foreach ( get_sites(
	[
		'fields' => 'ids',
		'number' => 0,
	]
) as $mwtfrs_site_id ) {
	switch_to_blog( (int) $mwtfrs_site_id );
	FeatureRequestSystem\Install\Uninstaller::run();
	restore_current_blog();
}
