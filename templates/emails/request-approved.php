<?php
/**
 * Email to the user who suggested a request, when it is approved. Plain text.
 *
 * To change the text, copy this file to yourtheme/feature-request-system/emails/request-approved.php.
 * Write text and line breaks only. The values are plain text. They are escaped here, as
 * in any template; before the email is sent, tags are removed and the escaped characters
 * are turned back, so a title with a quote or an ampersand arrives as it was typed.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Prepared by FeatureRequestSystem\Notifications\Mailer::send(): name (of the
 *                                 user), site, title, url, reason (why the user gets the email) and
 *                                 unsubscribe_url.
 */

defined( 'ABSPATH' ) || exit;

/* translators: %s: name of the person the email goes to. */
echo esc_html( sprintf( __( 'Hi %s,', 'feature-request-system' ), $args['name'] ) ) . "\n\n";

/* translators: %s: site name. */
echo esc_html( sprintf( __( 'Your feature request on %s was approved. Everyone can now see it and vote for it:', 'feature-request-system' ), $args['site'] ) ) . "\n\n";

echo esc_html( $args['title'] ) . "\n";
echo esc_url( $args['url'] ) . "\n\n";

echo esc_html__( 'You follow this request, so you will get an email when its status changes.', 'feature-request-system' ) . "\n\n";

echo "--\n";
echo esc_html( $args['reason'] ) . "\n";
echo esc_html__( 'Stop following it:', 'feature-request-system' ) . ' ' . esc_url( $args['unsubscribe_url'] ) . "\n";
