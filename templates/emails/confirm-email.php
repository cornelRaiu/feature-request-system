<?php
/**
 * Email with the link that confirms a visitor's address. Plain text.
 *
 * To change the text, copy this file to yourtheme/feature-request-system/emails/confirm-email.php.
 * Write text and line breaks only. The values are plain text. They are escaped here, as
 * in any template; before the email is sent, tags are removed and the escaped characters
 * are turned back.
 *
 * Anyone can type any address into a form, so the email does not greet the visitor by a
 * name or repeat what they wrote: it would carry a stranger's words to the address.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Prepared by FeatureRequestSystem\Guests\ConfirmationLink::send(): site, url (the
 *                                 link), hours (how long it works) and actions (what happens once the address is
 *                                 confirmed, one sentence each).
 */

defined( 'ABSPATH' ) || exit;

echo esc_html__( 'Hello,', 'feature-request-system' ) . "\n\n";

/* translators: %s: site name. */
echo esc_html( sprintf( __( 'Someone, probably you, used this email address to take part in the feature requests on %s.', 'feature-request-system' ), $args['site'] ) ) . "\n\n";

/* translators: %d: number of hours. */
echo esc_html( sprintf( _n( 'To confirm that it is yours, open this link within %d hour and press the button on its page:', 'To confirm that it is yours, open this link within %d hours and press the button on its page:', $args['hours'], 'feature-request-system' ), $args['hours'] ) ) . "\n";
echo esc_url( $args['url'] ) . "\n\n";

if ( $args['actions'] ) {
	echo esc_html__( 'Then:', 'feature-request-system' ) . "\n";
	foreach ( $args['actions'] as $mwtfrs_action ) {
		echo '- ' . esc_html( $mwtfrs_action ) . "\n";
	}
	echo "\n";
}

echo esc_html__( 'If it was not you, ignore this email. Nothing happens unless the button is pressed.', 'feature-request-system' ) . "\n";
