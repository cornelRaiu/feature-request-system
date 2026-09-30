<?php
/**
 * WordPress constants that are defined at runtime, for PHPStan when its WordPress extension lacks them.
 *
 * Their values here only give PHPStan a type. The config lists them as dynamic so no
 * code path is treated as always true or always false.
 *
 * @package FeatureRequestSystem
 */

defined( 'COOKIEPATH' ) || define( 'COOKIEPATH', '/' );
defined( 'COOKIE_DOMAIN' ) || define( 'COOKIE_DOMAIN', '' );
