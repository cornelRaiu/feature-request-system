<?php
/**
 * One feature request as a card, for a post or a page that talks about it.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/request-card.php.
 * Keep the data-wp-* attributes: they make voting work. Every value is escaped here, at
 * output. The request is drawn by request-item.php, and its vote button by vote-button.php.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Card data prepared by FeatureRequestSystem\Frontend\RequestCard::render().
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_item = $args['item'];
?>
<div
	<?php echo $args['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>"
	<?php echo wp_interactivity_data_wp_context( $args['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-init="callbacks.hydrate"
>
	<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>

	<div
		class="mwtfrs-request"
		id="<?php echo esc_attr( $mwtfrs_item['dom_id'] ); ?>"
		<?php echo wp_interactivity_data_wp_context( $mwtfrs_item['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
		data-wp-watch="callbacks.sync"
	>
		<?php echo FeatureRequestSystem\Frontend\Template::render( 'request-item', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The request template escapes every value. ?>
	</div>
</div>
