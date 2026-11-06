<?php
/**
 * Feature request board.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/board.php.
 * Keep the data-wp-* attributes and the form fields: they make voting work, with and
 * without JavaScript, and let the filters apply without a page load. Every value is
 * escaped here, at output. Each request is drawn by request-item.php, and its vote
 * button by vote-button.php.
 *
 * The filter form and the results are two interactive regions side by side. Live
 * filtering replaces only the results, so the control in use keeps its focus and what
 * is typed in it.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Board data prepared by FeatureRequestSystem\Frontend\Board::render().
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_prefix   = $args['id_prefix'];
$mwtfrs_filters  = $args['filters'];
$mwtfrs_params   = $args['params'];
$mwtfrs_controls = $args['controls'];
$mwtfrs_region   = $args['region'];
?>
<div <?php echo $args['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<?php if ( in_array( true, $mwtfrs_controls, true ) ) : ?>
		<form
			class="mwtfrs-board__filters"
			method="get"
			action="<?php echo esc_url( $args['page_url'] ); ?>"
			role="search"
			aria-label="<?php esc_attr_e( 'Search and filter requests', 'feature-request-system' ); ?>"
			data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>"
			<?php echo wp_interactivity_data_wp_context( $args['filters_context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
			data-wp-init="callbacks.startFilters"
			data-wp-on--submit="actions.filter"
			data-wp-on--change="actions.filter"
			data-wp-on-window--popstate="callbacks.restoreFilters"
		>
			<?php foreach ( $args['hidden_fields'] as $mwtfrs_name => $mwtfrs_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $mwtfrs_name ); ?>" value="<?php echo esc_attr( $mwtfrs_value ); ?>">
			<?php endforeach; ?>

			<?php if ( $mwtfrs_controls['search'] ) : ?>
				<p class="mwtfrs-board__field mwtfrs-board__field--search">
					<label for="<?php echo esc_attr( $mwtfrs_prefix . '-search' ); ?>"><?php esc_html_e( 'Search requests', 'feature-request-system' ); ?></label>
					<input type="search" id="<?php echo esc_attr( $mwtfrs_prefix . '-search' ); ?>" name="<?php echo esc_attr( $mwtfrs_params['search'] ); ?>" value="<?php echo esc_attr( $mwtfrs_filters['search'] ); ?>" data-wp-on--input="actions.search">
				</p>
			<?php endif; ?>

			<?php if ( $mwtfrs_controls['status'] ) : ?>
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
			<?php endif; ?>

			<?php if ( $mwtfrs_controls['category'] ) : ?>
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

			<?php if ( $mwtfrs_controls['sort'] ) : ?>
				<p class="mwtfrs-board__field">
					<label for="<?php echo esc_attr( $mwtfrs_prefix . '-sort' ); ?>"><?php esc_html_e( 'Sort by', 'feature-request-system' ); ?></label>
					<select id="<?php echo esc_attr( $mwtfrs_prefix . '-sort' ); ?>" name="<?php echo esc_attr( $mwtfrs_params['sort'] ); ?>">
						<?php foreach ( $args['sorts'] as $mwtfrs_value => $mwtfrs_label ) : ?>
							<option value="<?php echo esc_attr( $mwtfrs_value ); ?>" <?php selected( $mwtfrs_filters['sort'], $mwtfrs_value ); ?>><?php echo esc_html( $mwtfrs_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>

			<?php // With JavaScript, the filters apply as they change, and the button goes. ?>
			<p class="mwtfrs-board__field mwtfrs-board__field--submit" data-wp-bind--hidden="context.live">
				<button type="submit" class="mwtfrs-board__apply"><?php esc_html_e( 'Show requests', 'feature-request-system' ); ?></button>
			</p>
		</form>
	<?php endif; ?>

	<?php // Outside the results, so that it is still there to speak when they are replaced. ?>
	<p class="mwtfrs-sr-only" id="<?php echo esc_attr( $mwtfrs_region . '-summary' ); ?>" aria-live="polite"></p>

	<div class="mwtfrs-board__results" data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>" data-wp-router-region="<?php echo esc_attr( $mwtfrs_region ); ?>" tabindex="-1">
		<?php // A key of its own for each set of results, so that new results start afresh and their votes are fetched. ?>
		<div
			class="mwtfrs-board__page"
			data-wp-key="<?php echo esc_attr( $args['results_key'] ); ?>"
			<?php echo wp_interactivity_data_wp_context( $args['results_context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
			data-wp-init="callbacks.showResults"
		>
			<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>

			<?php if ( ! empty( $args['vote_limit'] ) ) : ?>
				<p class="mwtfrs-board__allowance">
					<?php
					/* translators: %d: number of votes. */
					echo esc_html( sprintf( _n( 'Each person can vote for %d request on this board.', 'Each person can vote for %d requests on this board.', $args['vote_limit'], 'feature-request-system' ), $args['vote_limit'] ) );
					?>
					<span data-wp-bind--hidden="!context.allowance" data-wp-text="context.allowance"><?php echo esc_html( $args['results_context']['allowance'] ?? '' ); ?></span>
				</p>
			<?php endif; ?>

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
					<nav class="mwtfrs-board__pagination" aria-label="<?php esc_attr_e( 'More requests', 'feature-request-system' ); ?>" data-wp-on--click="actions.turnPage">
						<?php echo $args['pagination']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by paginate_links(). ?>
					</nav>
				<?php endif; ?>
			<?php else : ?>
				<p class="mwtfrs-board__empty"><?php echo esc_html( $args['empty_message'] ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
