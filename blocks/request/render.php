<?php
/**
 * Server render of the Feature Request Details block.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Block content.
 * @var WP_Block             $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

echo FeatureRequestSystem\Frontend\RequestView::render_details( (int) ( $block->context['postId'] ?? 0 ), get_block_wrapper_attributes( [ 'class' => 'mwtfrs-single' ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The request template escapes every value.
