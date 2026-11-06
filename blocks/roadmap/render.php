<?php
/**
 * Server render of the Feature Request Roadmap block.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo FeatureRequestSystem\Frontend\Roadmap::render( $attributes, get_block_wrapper_attributes( [ 'class' => 'mwtfrs-roadmap' ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The roadmap template escapes every value.
