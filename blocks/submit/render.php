<?php
/**
 * Server render of the Feature Request Form block.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

echo FeatureRequestSystem\Frontend\SubmitForm::render( $attributes, get_block_wrapper_attributes( [ 'class' => 'mwtfrs-submit' ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The form template escapes every value.
