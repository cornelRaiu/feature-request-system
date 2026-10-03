<?php
/**
 * Details of a single feature request: its vote button, status and categories, and for
 * logged-in users the Follow button.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/request.php.
 * Keep the data-wp-* attributes and the form fields: they make voting and following work,
 * with and without JavaScript.
 * Every value is escaped here, at output. The vote button has its own template,
 * vote-button.php.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Request data prepared by FeatureRequestSystem\Frontend\RequestView::render_details().
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_item = $args['item'];
?>
<div
	<?php echo $args['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	id="<?php echo esc_attr( $mwtfrs_item['dom_id'] ); ?>"
	data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>"
	<?php echo wp_interactivity_data_wp_context( $args['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-init="callbacks.hydrate"
>
	<?php if ( '' !== $args['board_url'] ) : ?>
		<p class="mwtfrs-single__back">
			<a href="<?php echo esc_url( $args['board_url'] ); ?>">
				<span aria-hidden="true">&larr;</span>
				<?php
				/* translators: %s: board name. */
				echo esc_html( sprintf( __( 'Back to %s', 'feature-request-system' ), $args['board_name'] ) );
				?>
			</a>
		</p>
	<?php endif; ?>

	<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>

	<div
		class="mwtfrs-single__row"
		<?php echo wp_interactivity_data_wp_context( $mwtfrs_item['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
		data-wp-watch="callbacks.sync"
	>
		<?php echo FeatureRequestSystem\Frontend\Template::render( 'vote-button', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The vote button template escapes every value. ?>

		<div class="mwtfrs-single__info">
			<?php if ( $mwtfrs_item['status'] || $mwtfrs_item['categories'] ) : ?>
				<p class="mwtfrs-request__meta">
					<?php if ( $mwtfrs_item['status'] ) : ?>
						<span class="mwtfrs-status" style="--mwtfrs-status-color: <?php echo esc_attr( $mwtfrs_item['status']['color'] ); ?>"><?php echo esc_html( $mwtfrs_item['status']['name'] ); ?></span>
					<?php endif; ?>
					<?php foreach ( $mwtfrs_item['categories'] as $mwtfrs_category ) : ?>
						<span class="mwtfrs-category"><?php echo esc_html( $mwtfrs_category ); ?></span>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( $args['follow'] ) : ?>
				<form class="mwtfrs-follow" method="post" action="<?php echo esc_url( $args['follow']['url'] ); ?>" data-wp-on--submit="actions.toggleFollow">
					<input type="hidden" name="action" value="<?php echo esc_attr( $args['follow']['action'] ); ?>">
					<input type="hidden" name="request_id" value="<?php echo (int) $mwtfrs_item['id']; ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $args['follow']['redirect_to'] ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $args['follow']['nonce'] ); ?>">
					<span class="mwtfrs-follow__text" data-wp-bind--hidden="context.following"><?php esc_html_e( 'Get an email when the status changes.', 'feature-request-system' ); ?></span>
					<span class="mwtfrs-follow__text" data-wp-bind--hidden="!context.following"><?php esc_html_e( 'You get an email when the status changes.', 'feature-request-system' ); ?></span>
					<button type="submit" class="mwtfrs-follow__button" data-wp-bind--disabled="context.followBusy">
						<span data-wp-bind--hidden="context.following"><?php esc_html_e( 'Follow', 'feature-request-system' ); ?></span>
						<span data-wp-bind--hidden="!context.following"><?php esc_html_e( 'Stop following', 'feature-request-system' ); ?></span>
					</button>
				</form>
			<?php endif; ?>

			<p class="mwtfrs-request__message" role="status" data-wp-bind--hidden="!context.message">
				<span data-wp-text="context.message"><?php echo esc_html( $mwtfrs_item['context']['message'] ); ?></span>
				<a href="<?php echo esc_url( $args['login_url'] ); ?>" data-wp-bind--hidden="!context.needsLogin"><?php esc_html_e( 'Log in', 'feature-request-system' ); ?></a>
			</p>
		</div>
	</div>
</div>
