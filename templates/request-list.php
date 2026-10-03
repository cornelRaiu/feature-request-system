<?php
/**
 * A short list of feature requests, without search, filters or pages.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/request-list.php.
 * Keep the data-wp-* attributes: they make voting work. Every value is escaped here, at
 * output. Each request is drawn by request-item.php, and its vote button by vote-button.php.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args List data prepared by FeatureRequestSystem\Frontend\CompactBoard::render().
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	<?php echo $args['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>"
	<?php echo wp_interactivity_data_wp_context( $args['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-init="callbacks.hydrate"
>
	<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>

	<?php if ( $args['items'] ) : ?>
		<ul class="mwtfrs-board__list">
			<?php foreach ( $args['items'] as $mwtfrs_item ) : ?>
				<li
					class="mwtfrs-request"
					id="<?php echo esc_attr( $mwtfrs_item['dom_id'] ); ?>"
					<?php echo wp_interactivity_data_wp_context( $mwtfrs_item['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
					data-wp-watch="callbacks.sync"
				>
					<?php echo FeatureRequestSystem\Frontend\Template::render( 'request-item', [ 'item' => $mwtfrs_item ] + $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The request template escapes every value. ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="mwtfrs-board__empty"><?php esc_html_e( 'Nothing to show here yet.', 'feature-request-system' ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $args['more_url'] ) : ?>
		<p class="mwtfrs-board__more"><a href="<?php echo esc_url( $args['more_url'] ); ?>"><?php esc_html_e( 'See all requests', 'feature-request-system' ); ?></a></p>
	<?php endif; ?>
</div>
