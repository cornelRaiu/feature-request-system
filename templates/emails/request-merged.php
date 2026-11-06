<?php
/**
 * Email to a follower of a request that was merged into another. Plain text.
 *
 * To change the text, copy this file to yourtheme/feature-request-system/emails/request-merged.php.
 * Write text and line breaks only. The values are plain text. They are escaped here, as
 * in any template; before the email is sent, tags are removed and the escaped characters
 * are turned back, so a title with a quote or an ampersand arrives as it was typed.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args Prepared by FeatureRequestSystem\Notifications\Mailer::send(): name (of the
 *                                 follower), site, merged_title (the request that was merged), title and url
 *                                 (of the request it was merged into), voted (whether the follower's vote
 *                                 counts there), reason (why the follower gets the email) and unsubscribe_url.
 */

defined( 'ABSPATH' ) || exit;

/* translators: %s: name of the person the email goes to. */
echo esc_html( sprintf( __( 'Hi %s,', 'feature-request-system' ), $args['name'] ) ) . "\n\n";

/* translators: %s: site name. */
echo esc_html( sprintf( __( 'A feature request you follow on %s asked for the same as another one, so the two were merged.', 'feature-request-system' ), $args['site'] ) ) . "\n\n";

if ( $args['voted'] ) {
	/* translators: 1: title of the merged request, 2: title of the request it was merged into. */
	echo esc_html( sprintf( __( '"%1$s" was merged into "%2$s", and your vote now counts there.', 'feature-request-system' ), $args['merged_title'], $args['title'] ) ) . "\n\n";
} else {
	/* translators: 1: title of the merged request, 2: title of the request it was merged into. */
	echo esc_html( sprintf( __( '"%1$s" was merged into "%2$s".', 'feature-request-system' ), $args['merged_title'], $args['title'] ) ) . "\n\n";
}

echo esc_html__( 'You now follow that request instead, so you will get an email when its status changes:', 'feature-request-system' ) . "\n";
echo esc_url( $args['url'] ) . "\n\n";

echo "--\n";
echo esc_html( $args['reason'] ) . "\n";
echo esc_html__( 'Stop following it:', 'feature-request-system' ) . ' ' . esc_url( $args['unsubscribe_url'] ) . "\n";
