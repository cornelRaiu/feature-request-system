<?php
/**
 * Akismet spam check for submitted requests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Sends new requests to Akismet when the Akismet plugin is active and has an API key.
 *
 * A request Akismet flags is not thrown away; it is held for review like any request
 * waiting for approval, because spam checks sometimes get it wrong.
 */
final class Akismet {

	/**
	 * Hooks into the spam check.
	 */
	public static function add_hooks(): void {
		add_filter( SpamGuard::FILTER_SPAM, [ self::class, 'check' ], 10, 2 );
	}

	/**
	 * Whether the Akismet plugin is active and has an API key.
	 */
	public static function is_connected(): bool {
		return class_exists( '\Akismet' ) && \Akismet::get_api_key();
	}

	/**
	 * Asks Akismet about a submission.
	 *
	 * @param bool                                                                   $is_spam    Verdict so far.
	 * @param array{title: string, description: string, name: string, email: string} $submission Submitted request.
	 */
	public static function check( bool $is_spam, array $submission ): bool {
		if ( $is_spam || ! self::is_connected() ) {
			return $is_spam;
		}

		$query = [
			'blog'                 => home_url(),
			'blog_lang'            => get_locale(),
			'blog_charset'         => get_option( 'blog_charset' ),
			'user_ip'              => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'             => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'comment_type'         => 'forum-post',
			'comment_author'       => $submission['name'],
			'comment_author_email' => $submission['email'],
			'comment_content'      => $submission['title'] . "\n\n" . $submission['description'],
		];

		// Akismet answers with its response headers and body. The body is "true" for spam.
		$response = \Akismet::http_post( \Akismet::build_query( $query ), 'comment-check' );

		return 'true' === trim( $response[1] );
	}
}
