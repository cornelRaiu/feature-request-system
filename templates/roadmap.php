<?php
/**
 * Feature request roadmap: a column for each status on the roadmap.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/roadmap.php.
 * Keep the data-wp-* attributes: they make voting and "Show more" work. Without
 * JavaScript, "Show more" is an ordinary link. Every value is escaped here, at output.
 * Each request is drawn by request-item.php, and its vote button by vote-button.php.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Roadmap data prepared by FeatureRequestSystem\Frontend\Roadmap::render().
 */

defined( 'ABSPATH' ) || exit;
?>
<div
	<?php echo $args['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>"
	data-wp-router-region="<?php echo esc_attr( $args['region'] ); ?>"
	<?php echo wp_interactivity_data_wp_context( $args['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-init="callbacks.hydrate"
>
	<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>

	<?php if ( $args['columns'] ) : ?>
		<div class="mwtfrs-roadmap__columns">
			<?php foreach ( $args['columns'] as $mwtfrs_column ) : ?>
				<div
					class="mwtfrs-roadmap__column"
					style="--mwtfrs-status-color: <?php echo esc_attr( $mwtfrs_column['color'] ); ?>"
					data-wp-key="<?php echo esc_attr( $mwtfrs_column['key'] ); ?>"
					<?php echo wp_interactivity_data_wp_context( $mwtfrs_column['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
					data-wp-init="callbacks.showMoreShown"
				>
					<h3 class="mwtfrs-roadmap__heading" id="<?php echo esc_attr( $mwtfrs_column['heading_id'] ); ?>">
						<span class="mwtfrs-status"><?php echo esc_html( $mwtfrs_column['name'] ); ?></span>
						<span class="mwtfrs-roadmap__count" aria-hidden="true"><?php echo (int) $mwtfrs_column['total']; ?></span>
						<span class="mwtfrs-sr-only">
							<?php
							/* translators: %s: number of requests in the column. */
							echo esc_html( sprintf( _n( '%s request', '%s requests', $mwtfrs_column['total'], 'feature-request-system' ), number_format_i18n( $mwtfrs_column['total'] ) ) );
							?>
						</span>
					</h3>

					<?php if ( $mwtfrs_column['items'] ) : ?>
						<ul class="mwtfrs-roadmap__list">
							<?php foreach ( $mwtfrs_column['items'] as $mwtfrs_item ) : ?>
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
						<p class="mwtfrs-roadmap__empty"><?php esc_html_e( 'No requests here yet.', 'feature-request-system' ); ?></p>
					<?php endif; ?>

					<?php if ( '' !== $mwtfrs_column['more_url'] ) : ?>
						<p class="mwtfrs-roadmap__more">
							<a href="<?php echo esc_url( $mwtfrs_column['more_url'] ); ?>" aria-describedby="<?php echo esc_attr( $mwtfrs_column['heading_id'] ); ?>" data-wp-on--click="actions.showMore"><?php esc_html_e( 'Show more', 'feature-request-system' ); ?></a>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="mwtfrs-roadmap__empty"><?php esc_html_e( 'Nothing is on the roadmap yet.', 'feature-request-system' ); ?></p>
	<?php endif; ?>
</div>
