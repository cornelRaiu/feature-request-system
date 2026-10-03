<?php
/**
 * Server render of the Feature Request Card block.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo FeatureRequestSystem\Frontend\RequestCard::render( $attributes, get_block_wrapper_attributes( [ 'class' => 'mwtfrs-card' ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The card template escapes every value.
