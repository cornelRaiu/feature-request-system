<?php
/**
 * Form for suggesting a feature.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/submit-form.php.
 * Keep the data-wp-* attributes and the form fields: they make the form work, with and
 * without JavaScript. Every value is escaped here, at output.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Form data prepared by FeatureRequestSystem\Frontend\SubmitForm::render().
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_prefix = $args['id_prefix'];
$mwtfrs_limits = $args['limits'];
?>
<div
	<?php echo $args['wrapper_attributes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	id="<?php echo esc_attr( $args['anchor'] ); ?>"
	data-wp-interactive="<?php echo esc_attr( $args['namespace'] ); ?>"
	<?php echo wp_interactivity_data_wp_context( $args['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
>
	<h2 class="mwtfrs-submit__heading" id="<?php echo esc_attr( $mwtfrs_prefix . '-heading' ); ?>"><?php esc_html_e( 'Suggest a feature', 'feature-request-system' ); ?></h2>

	<?php if ( 'closed' === $args['mode'] ) : ?>
		<p class="mwtfrs-submit__notice"><?php esc_html_e( 'This board is not taking new requests.', 'feature-request-system' ); ?></p>
	<?php elseif ( 'login' === $args['mode'] ) : ?>
		<p class="mwtfrs-submit__notice"><?php esc_html_e( 'Log in to suggest a feature.', 'feature-request-system' ); ?></p>
		<p class="mwtfrs-submit__actions">
			<a class="mwtfrs-submit__button" href="<?php echo esc_url( $args['login_url'] ); ?>"><?php esc_html_e( 'Log in', 'feature-request-system' ); ?></a>
			<?php if ( '' !== $args['register_url'] ) : ?>
				<a href="<?php echo esc_url( $args['register_url'] ); ?>"><?php esc_html_e( 'Create an account', 'feature-request-system' ); ?></a>
			<?php endif; ?>
		</p>
	<?php else : ?>
		<form
			class="mwtfrs-submit__form"
			method="post"
			action="<?php echo esc_url( $args['form_url'] ); ?>"
			aria-labelledby="<?php echo esc_attr( $mwtfrs_prefix . '-heading' ); ?>"
			data-wp-on--submit="actions.submit"
		>
			<input type="hidden" name="action" value="<?php echo esc_attr( $args['form_action'] ); ?>">
			<input type="hidden" name="board" value="<?php echo (int) $args['board_id']; ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $args['redirect_to'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $args['token_field'] ); ?>" value="<?php echo esc_attr( $args['token'] ); ?>">
			<?php if ( '' !== $args['nonce'] ) : ?>
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $args['nonce'] ); ?>">
			<?php endif; ?>

			<p class="mwtfrs-submit__field">
				<label for="<?php echo esc_attr( $mwtfrs_prefix . '-title' ); ?>"><?php esc_html_e( 'Title', 'feature-request-system' ); ?></label>
				<input
					type="text"
					id="<?php echo esc_attr( $mwtfrs_prefix . '-title' ); ?>"
					name="title"
					required
					maxlength="<?php echo (int) $mwtfrs_limits['title']; ?>"
					autocomplete="off"
					aria-describedby="<?php echo esc_attr( $mwtfrs_prefix . '-title-help' ); ?>"
					data-wp-on--input="actions.search"
				>
				<span class="mwtfrs-submit__help" id="<?php echo esc_attr( $mwtfrs_prefix . '-title-help' ); ?>"><?php esc_html_e( 'One short sentence, such as "Export requests as CSV".', 'feature-request-system' ); ?></span>
			</p>

			<div class="mwtfrs-submit__similar" data-wp-bind--hidden="!context.similar.length">
				<p class="mwtfrs-submit__similar-heading" id="<?php echo esc_attr( $mwtfrs_prefix . '-similar' ); ?>"><?php esc_html_e( 'Already requested? Vote for one of these instead:', 'feature-request-system' ); ?></p>
				<ul class="mwtfrs-submit__similar-list" aria-labelledby="<?php echo esc_attr( $mwtfrs_prefix . '-similar' ); ?>">
					<template data-wp-each--item="context.similar" data-wp-each-key="context.item.id">
						<li>
							<a data-wp-bind--href="context.item.url" data-wp-text="context.item.title"></a>
							<span class="mwtfrs-submit__similar-meta" data-wp-text="context.item.label"></span>
						</li>
					</template>
				</ul>
			</div>
			<p class="mwtfrs-sr-only" aria-live="polite" data-wp-text="context.announcement"></p>

			<p class="mwtfrs-submit__field">
				<label for="<?php echo esc_attr( $mwtfrs_prefix . '-description' ); ?>">
					<?php esc_html_e( 'Details', 'feature-request-system' ); ?>
					<span class="mwtfrs-submit__optional"><?php esc_html_e( '(optional)', 'feature-request-system' ); ?></span>
				</label>
				<textarea
					id="<?php echo esc_attr( $mwtfrs_prefix . '-description' ); ?>"
					name="description"
					rows="5"
					maxlength="<?php echo (int) $mwtfrs_limits['description']; ?>"
				></textarea>
			</p>

			<?php if ( $args['categories'] ) : ?>
				<p class="mwtfrs-submit__field">
					<label for="<?php echo esc_attr( $mwtfrs_prefix . '-category' ); ?>">
						<?php esc_html_e( 'Category', 'feature-request-system' ); ?>
						<span class="mwtfrs-submit__optional"><?php esc_html_e( '(optional)', 'feature-request-system' ); ?></span>
					</label>
					<select id="<?php echo esc_attr( $mwtfrs_prefix . '-category' ); ?>" name="category">
						<option value=""><?php esc_html_e( 'Choose a category', 'feature-request-system' ); ?></option>
						<?php foreach ( $args['categories'] as $mwtfrs_id => $mwtfrs_name ) : ?>
							<option value="<?php echo (int) $mwtfrs_id; ?>"><?php echo esc_html( $mwtfrs_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>

			<?php if ( $args['guest_fields'] ) : ?>
				<fieldset class="mwtfrs-submit__about">
					<legend class="mwtfrs-submit__legend">
						<?php esc_html_e( 'About you', 'feature-request-system' ); ?>
						<span class="mwtfrs-submit__optional"><?php esc_html_e( '(optional)', 'feature-request-system' ); ?></span>
					</legend>
					<p class="mwtfrs-submit__help" id="<?php echo esc_attr( $mwtfrs_prefix . '-about-help' ); ?>"><?php esc_html_e( 'Only our team sees this, in case we have a question about your request.', 'feature-request-system' ); ?></p>
					<div class="mwtfrs-submit__row">
						<p class="mwtfrs-submit__field">
							<label for="<?php echo esc_attr( $mwtfrs_prefix . '-name' ); ?>"><?php esc_html_e( 'Name', 'feature-request-system' ); ?></label>
							<input type="text" id="<?php echo esc_attr( $mwtfrs_prefix . '-name' ); ?>" name="name" maxlength="<?php echo (int) $mwtfrs_limits['name']; ?>" autocomplete="name" aria-describedby="<?php echo esc_attr( $mwtfrs_prefix . '-about-help' ); ?>">
						</p>
						<p class="mwtfrs-submit__field">
							<label for="<?php echo esc_attr( $mwtfrs_prefix . '-email' ); ?>"><?php esc_html_e( 'Email', 'feature-request-system' ); ?></label>
							<input type="email" id="<?php echo esc_attr( $mwtfrs_prefix . '-email' ); ?>" name="email" autocomplete="email" aria-describedby="<?php echo esc_attr( $mwtfrs_prefix . '-about-help' ); ?>">
						</p>
					</div>

					<?php if ( '' !== $args['data_use'] ) : ?>
						<details class="mwtfrs-submit__data-use">
							<summary><?php esc_html_e( 'How we use your data', 'feature-request-system' ); ?></summary>
							<div class="mwtfrs-submit__data-use-text">
								<?php echo wp_kses_post( $args['data_use'] ); ?>
								<?php if ( '' !== $args['privacy_url'] ) : ?>
									<p><a href="<?php echo esc_url( $args['privacy_url'] ); ?>"><?php esc_html_e( 'Privacy policy', 'feature-request-system' ); ?></a></p>
								<?php endif; ?>
							</div>
						</details>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<?php // A field people never see. Bots that fill in every field give themselves away. ?>
			<p class="mwtfrs-trap" aria-hidden="true">
				<label for="<?php echo esc_attr( $mwtfrs_prefix . '-trap' ); ?>"><?php esc_html_e( 'Leave this field empty', 'feature-request-system' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $mwtfrs_prefix . '-trap' ); ?>" name="<?php echo esc_attr( $args['trap_field'] ); ?>" value="" tabindex="-1" autocomplete="off">
			</p>

			<p class="mwtfrs-submit__actions">
				<button type="submit" class="mwtfrs-submit__button" data-wp-bind--disabled="context.busy"><?php esc_html_e( 'Send request', 'feature-request-system' ); ?></button>
			</p>

			<div class="mwtfrs-submit__status" role="status">
				<p class="mwtfrs-submit__message" data-wp-class--is-error="context.isError" data-wp-bind--hidden="!context.message">
					<span data-wp-text="context.message"><?php echo esc_html( $args['context']['message'] ); ?></span>
					<a data-wp-bind--href="context.url" data-wp-bind--hidden="!context.url"><?php esc_html_e( 'View your request', 'feature-request-system' ); ?></a>
					<a href="<?php echo esc_url( $args['login_url'] ); ?>" data-wp-bind--hidden="!context.needsLogin"><?php esc_html_e( 'Log in', 'feature-request-system' ); ?></a>
				</p>
			</div>
		</form>
	<?php endif; ?>
</div>
