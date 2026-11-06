<?php
/**
 * Form for an email address next to a vote button, on boards where visitors vote once
 * they confirm their email. It stays hidden until a visitor who has not confirmed one votes.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/guest-email-form.php.
 * Keep the data-wp-* attributes and the form fields: they make the form work, with and
 * without JavaScript. The form reads the request's data-wp-context from an element
 * around it, as the vote button does. Every value is escaped here, at output.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args The args of the template that shows the request. Of those, "item" is the request,
 *                                 prepared by FeatureRequestSystem\Frontend\RequestItem::prepare(), and "vote" is the
 *                                 vote form's settings, from FeatureRequestSystem\Frontend\VoteForm::template_args(),
 *                                 whose "email" holds this form's own.
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_item  = $args['item'];
$mwtfrs_vote  = $args['vote'];
$mwtfrs_email = $mwtfrs_vote['email'];
$mwtfrs_id    = $mwtfrs_item['dom_id'] . '-email';
?>
<form
	class="mwtfrs-guest"
	method="post"
	action="<?php echo esc_url( $mwtfrs_vote['url'] ); ?>"
	<?php echo $mwtfrs_item['context']['needsEmail'] ? '' : 'hidden'; ?>
	data-wp-bind--hidden="!context.needsEmail"
	data-wp-on--submit="actions.requestLink"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( $mwtfrs_email['action'] ); ?>">
	<input type="hidden" name="request_id" value="<?php echo (int) $mwtfrs_item['id']; ?>">
	<input type="hidden" name="redirect_to" value="<?php echo esc_url( $mwtfrs_vote['redirect_to'] . '#' . $mwtfrs_item['dom_id'] ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $mwtfrs_email['token_field'] ); ?>" value="<?php echo esc_attr( $mwtfrs_email['token'] ); ?>">

	<label class="mwtfrs-guest__label" for="<?php echo esc_attr( $mwtfrs_id ); ?>"><?php esc_html_e( 'Your email address', 'feature-request-system' ); ?></label>
	<span class="mwtfrs-guest__row">
		<input
			type="email"
			class="mwtfrs-guest__email"
			id="<?php echo esc_attr( $mwtfrs_id ); ?>"
			name="email"
			required
			maxlength="<?php echo (int) $mwtfrs_email['max_length']; ?>"
			autocomplete="email"
			aria-describedby="<?php echo esc_attr( $mwtfrs_id . '-help' ); ?>"
		>
		<button type="submit" class="mwtfrs-guest__button" data-wp-bind--disabled="context.busy"><?php esc_html_e( 'Send me the link', 'feature-request-system' ); ?></button>
	</span>
	<span class="mwtfrs-guest__help" id="<?php echo esc_attr( $mwtfrs_id . '-help' ); ?>"><?php esc_html_e( 'We email you a link to confirm it. Only our team sees your address.', 'feature-request-system' ); ?></span>

	<?php // A field people never see. Bots that fill in every field give themselves away. ?>
	<span class="mwtfrs-guest__trap" aria-hidden="true">
		<label for="<?php echo esc_attr( $mwtfrs_id . '-trap' ); ?>"><?php esc_html_e( 'Leave this field empty', 'feature-request-system' ); ?></label>
		<input type="text" id="<?php echo esc_attr( $mwtfrs_id . '-trap' ); ?>" name="<?php echo esc_attr( $mwtfrs_email['trap_field'] ); ?>" value="" tabindex="-1" autocomplete="off">
	</span>
</form>
