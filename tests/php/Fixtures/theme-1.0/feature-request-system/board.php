<?php
/**
 * Feature request board.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/board.php.
 * Keep the data-wp-* attributes and the form fields: they make voting work, with and
 * without JavaScript. Every value is escaped here, at output. Each request is drawn by
 * request-item.php, and its vote button by vote-button.php.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Board data prepared by FeatureRequestSystem\Frontend\Board::render().
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_prefix  = $args['id_prefix'];
$mwtfrs_filters = $args['filters'];
$mwtfrs_params  = $args['params'];
?>
<div
	<?php echo $args['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>"
	<?php echo wp_interactivity_data_wp_context( $args['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-init="callbacks.hydrate"
>
	<form class="mwtfrs-board__filters" method="get" action="<?php echo esc_url( $args['page_url'] ); ?>" role="search" aria-label="<?php esc_attr_e( 'Search and filter requests', 'feature-request-system' ); ?>">
		<?php foreach ( $args['hidden_fields'] as $mwtfrs_name => $mwtfrs_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $mwtfrs_name ); ?>" value="<?php echo esc_attr( $mwtfrs_value ); ?>">
		<?php endforeach; ?>

		<p class="mwtfrs-board__field mwtfrs-board__field--search">
			<label for="<?php echo esc_attr( $mwtfrs_prefix . '-search' ); ?>"><?php esc_html_e( 'Search requests', 'feature-request-system' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $mwtfrs_prefix . '-search' ); ?>" name="<?php echo esc_attr( $mwtfrs_params['search'] ); ?>" value="<?php echo esc_attr( $mwtfrs_filters['search'] ); ?>">
		</p>

		<p class="mwtfrs-board__field">
			<label for="<?php echo esc_attr( $mwtfrs_prefix . '-status' ); ?>"><?php esc_html_e( 'Status', 'feature-request-system' ); ?></label>
			<select id="<?php echo esc_attr( $mwtfrs_prefix . '-status' ); ?>" name="<?php echo esc_attr( $mwtfrs_params['status'] ); ?>">
				<option value=""><?php esc_html_e( 'Open for voting', 'feature-request-system' ); ?></option>
				<option value="<?php echo esc_attr( $args['status_all'] ); ?>" <?php selected( $mwtfrs_filters['status'], $args['status_all'] ); ?>><?php esc_html_e( 'All statuses', 'feature-request-system' ); ?></option>
				<?php foreach ( $args['statuses'] as $mwtfrs_slug => $mwtfrs_label ) : ?>
					<option value="<?php echo esc_attr( $mwtfrs_slug ); ?>" <?php selected( $mwtfrs_filters['status'], $mwtfrs_slug ); ?>><?php echo esc_html( $mwtfrs_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php if ( $args['categories'] ) : ?>
			<p class="mwtfrs-board__field">
				<label for="<?php echo esc_attr( $mwtfrs_prefix . '-category' ); ?>"><?php esc_html_e( 'Category', 'feature-request-system' ); ?></label>
				<select id="<?php echo esc_attr( $mwtfrs_prefix . '-category' ); ?>" name="<?php echo esc_attr( $mwtfrs_params['category'] ); ?>">
					<option value=""><?php esc_html_e( 'All categories', 'feature-request-system' ); ?></option>
					<?php foreach ( $args['categories'] as $mwtfrs_slug => $mwtfrs_label ) : ?>
						<option value="<?php echo esc_attr( $mwtfrs_slug ); ?>" <?php selected( $mwtfrs_filters['category'], $mwtfrs_slug ); ?>><?php echo esc_html( $mwtfrs_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<p class="mwtfrs-board__field">
			<label for="<?php echo esc_attr( $mwtfrs_prefix . '-sort' ); ?>"><?php esc_html_e( 'Sort by', 'feature-request-system' ); ?></label>
			<select id="<?php echo esc_attr( $mwtfrs_prefix . '-sort' ); ?>" name="<?php echo esc_attr( $mwtfrs_params['sort'] ); ?>">
				<?php foreach ( $args['sorts'] as $mwtfrs_value => $mwtfrs_label ) : ?>
					<option value="<?php echo esc_attr( $mwtfrs_value ); ?>" <?php selected( $mwtfrs_filters['sort'], $mwtfrs_value ); ?>><?php echo esc_html( $mwtfrs_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="mwtfrs-board__field mwtfrs-board__field--submit">
			<button type="submit" class="mwtfrs-board__apply"><?php esc_html_e( 'Show requests', 'feature-request-system' ); ?></button>
		</p>
	</form>

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

		<?php if ( '' !== $args['pagination'] ) : ?>
			<nav class="mwtfrs-board__pagination" aria-label="<?php esc_attr_e( 'More requests', 'feature-request-system' ); ?>">
				<?php echo $args['pagination']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by paginate_links(). ?>
			</nav>
		<?php endif; ?>
	<?php else : ?>
		<p class="mwtfrs-board__empty"><?php echo esc_html( $args['empty_message'] ); ?></p>
	<?php endif; ?>
</div>
