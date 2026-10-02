<?php
/**
 * Email to a follower of a request when its status changes. Plain text.
 *
 * To change the text, copy this file to yourtheme/feature-request-system/emails/status-changed.php.
 * Write text and line breaks only. The values are plain text. They are escaped here, as
 * in any template; before the email is sent, tags are removed and the escaped characters
 * are turned back, so a title with a quote or an ampersand arrives as it was typed.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Prepared by FeatureRequestSystem\Notifications\Mailer::send(): name (of the
 *                                 follower), site, title, url, status, old_status ('' when unknown), reason
 *                                 (why the follower gets the email) and unsubscribe_url.
 */

defined( 'ABSPATH' ) || exit;

/* translators: %s: name of the person the email goes to. */
echo esc_html( sprintf( __( 'Hi %s,', 'feature-request-system' ), $args['name'] ) ) . "\n\n";

/* translators: %s: site name. */
echo esc_html( sprintf( __( 'A feature request you follow on %s has a new status.', 'feature-request-system' ), $args['site'] ) ) . "\n\n";

echo esc_html( $args['title'] ) . "\n";
if ( '' !== $args['old_status'] ) {
	/* translators: 1: new status name, such as Planned, 2: previous status name. */
	echo esc_html( sprintf( __( 'Status: %1$s (was %2$s)', 'feature-request-system' ), $args['status'], $args['old_status'] ) ) . "\n\n";
} else {
	/* translators: %s: status name, such as Planned. */
	echo esc_html( sprintf( __( 'Status: %s', 'feature-request-system' ), $args['status'] ) ) . "\n\n";
}

echo esc_html__( 'Read more and join the discussion:', 'feature-request-system' ) . "\n";
echo esc_url( $args['url'] ) . "\n\n";

echo "--\n";
echo esc_html( $args['reason'] ) . "\n";
echo esc_html__( 'Stop following it:', 'feature-request-system' ) . ' ' . esc_url( $args['unsubscribe_url'] ) . "\n";
