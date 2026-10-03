<?php
/**
 * Server render of the Feature Request Board block: the board, or its compact form, a short list.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- The board and list templates escape every value.
if ( empty( $attributes['compact'] ) ) {
	echo FeatureRequestSystem\Frontend\Board::render( $attributes, get_block_wrapper_attributes( [ 'class' => 'mwtfrs-board' ] ) );
} else {
	echo FeatureRequestSystem\Frontend\CompactBoard::render( $attributes, get_block_wrapper_attributes( [ 'class' => 'mwtfrs-board mwtfrs-board--compact' ] ) );
}
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
