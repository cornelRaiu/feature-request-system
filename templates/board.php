<?php
/**
 * Feature request board.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/board.php.
 * Keep the data-wp-* attributes and the form fields: they make voting work, with and
 * without JavaScript. Every value is escaped here, at output.
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
	<form class="mwtfrs-board__filters" method="get" action="<?php echo esc_url( $args['page_url'] ); ?>" role="search">
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

	<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="state.announcement"></p>

	<?php if ( $args['items'] ) : ?>
		<ul class="mwtfrs-board__list">
			<?php foreach ( $args['items'] as $mwtfrs_item ) : ?>
				<li
					class="mwtfrs-request"
					id="<?php echo esc_attr( $mwtfrs_item['dom_id'] ); ?>"
					<?php echo wp_interactivity_data_wp_context( $mwtfrs_item['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
					data-wp-watch="callbacks.syncVoted"
				>
					<?php if ( $mwtfrs_item['votable'] ) : ?>
						<form class="mwtfrs-request__vote" method="post" action="<?php echo esc_url( $args['vote_url'] ); ?>" data-wp-on--submit="actions.toggle">
							<input type="hidden" name="action" value="<?php echo esc_attr( $args['vote_action'] ); ?>">
							<input type="hidden" name="request_id" value="<?php echo (int) $mwtfrs_item['id']; ?>">
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $args['current_url'] ); ?>">
							<?php if ( $args['vote_nonce'] ) : ?>
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $args['vote_nonce'] ); ?>">
							<?php endif; ?>
							<button type="submit" class="mwtfrs-vote" data-wp-bind--aria-pressed="context.voted" data-wp-bind--disabled="context.busy">
								<svg class="mwtfrs-vote__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 4 4.5 12.5h5V20h5v-7.5h5z" /></svg>
								<span class="mwtfrs-vote__count" data-wp-text="context.votes"><?php echo (int) $mwtfrs_item['votes']; ?></span>
								<span class="mwtfrs-sr-only">
									<?php
									/* translators: %s: request title. */
									echo esc_html( sprintf( __( 'votes. Vote for %s', 'feature-request-system' ), $mwtfrs_item['title'] ) );
									?>
								</span>
							</button>
						</form>
					<?php else : ?>
						<p class="mwtfrs-request__vote mwtfrs-vote mwtfrs-vote--closed">
							<span class="mwtfrs-vote__count"><?php echo (int) $mwtfrs_item['votes']; ?></span>
							<span class="mwtfrs-sr-only"><?php esc_html_e( 'votes. Voting is closed.', 'feature-request-system' ); ?></span>
						</p>
					<?php endif; ?>

					<div class="mwtfrs-request__body">
						<h3 class="mwtfrs-request__title"><a href="<?php echo esc_url( $mwtfrs_item['url'] ); ?>"><?php echo esc_html( $mwtfrs_item['title'] ); ?></a></h3>

						<?php if ( '' !== $mwtfrs_item['excerpt'] ) : ?>
							<p class="mwtfrs-request__excerpt"><?php echo esc_html( $mwtfrs_item['excerpt'] ); ?></p>
						<?php endif; ?>

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

						<p class="mwtfrs-request__message" role="status" data-wp-bind--hidden="!context.message">
							<span data-wp-text="context.message"><?php echo esc_html( $mwtfrs_item['context']['message'] ); ?></span>
							<a href="<?php echo esc_url( $args['login_url'] ); ?>" data-wp-bind--hidden="!context.needsLogin"><?php esc_html_e( 'Log in', 'feature-request-system' ); ?></a>
						</p>
					</div>
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
