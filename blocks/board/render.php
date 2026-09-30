<?php
/**
 * Server render of the Feature Request Board block.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo FeatureRequestSystem\Frontend\Board::render( $attributes, get_block_wrapper_attributes( [ 'class' => 'mwtfrs-board' ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The board template escapes every value.
