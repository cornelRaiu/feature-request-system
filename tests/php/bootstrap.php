<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with this plugin running.
 *
 * @package FeatureRequestSystem
 */

$mwtfrs_root = dirname( __DIR__, 2 );

require_once $mwtfrs_root . '/vendor/autoload.php';

// Hosted setups such as wp-env provide their own test library. Otherwise use the wp-phpunit package.
$mwtfrs_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $mwtfrs_tests_dir ) {
	$mwtfrs_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $mwtfrs_root . '/vendor/yoast/phpunit-polyfills' );

require_once $mwtfrs_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $mwtfrs_root ) {
		require $mwtfrs_root . '/feature-request-system.php';
	}
);

require $mwtfrs_tests_dir . '/includes/bootstrap.php';
