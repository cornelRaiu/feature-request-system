<?php
/**
 * The question asked when the plugin is deactivated.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Asks why the plugin is being deactivated, on the Plugins screen.
 *
 * Clicking Deactivate opens a short form. Answering is optional, and nothing leaves the
 * site unless the user picks an answer and clicks "Send & Deactivate". Then the answer,
 * any text typed with it, the plugin, WordPress and PHP versions and the site language go
 * to master-webtools.com, where every Master WebTools plugin's answers are kept. The site
 * address is not sent: the request has its own user agent instead of WordPress's, which
 * names the site. The page deactivates the plugin after a few seconds at most, whatever
 * the answer's fate.
 *
 * "It's temporary" sends nothing, and can stop the question for 24 hours for that user.
 * Without JavaScript, or in a browser without the dialog element, Deactivate works as usual.
 */
final class DeactivationFeedback {

	/**
	 * The admin-ajax action, also the nonce action.
	 */
	public const ACTION = 'mwtfrs_deactivation_feedback';

	/**
	 * User meta: the time until which the question isn't asked.
	 */
	public const META_SNOOZE = 'mwtfrs_deactivation_feedback_snooze';

	/**
	 * Where the answers go.
	 */
	public const FEEDBACK_URL = 'https://www.master-webtools.com/api/v1/feedback';

	/**
	 * The privacy policy that covers the answers.
	 */
	public const PRIVACY_URL = 'https://www.master-webtools.com/privacy-policy/';

	/**
	 * The plugin's support forum on WordPress.org.
	 */
	public const SUPPORT_URL = 'https://wordpress.org/support/plugin/feature-request-system/';

	/**
	 * The plugin's slug, as the answers name it.
	 */
	private const SLUG = 'feature-request-system';

	/**
	 * The answer that sends nothing.
	 */
	private const TEMPORARY = 'temporary';

	/**
	 * The answer that is sent only with a reason typed in.
	 */
	private const OTHER = 'other';

	/**
	 * The longest text sent, in characters.
	 */
	private const MAX_DETAILS = 1000;

	/**
	 * HTML id of the dialog, and the start of the ids inside it.
	 */
	private const ID = 'mwtfrs-df';

	/**
	 * Handle of the script and the stylesheet.
	 */
	private const HANDLE = 'mwtfrs-deactivation-feedback';

	/**
	 * Registers the hooks.
	 */
	public static function add_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_footer', [ self::class, 'render' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle_answer' ] );
	}

	/**
	 * The answers that are sent, by the code that is sent. "It's temporary" and "Other" are offered too.
	 *
	 * @return array<string, string>
	 */
	public static function reasons(): array {
		return [
			'stopped-working'  => __( 'It stopped working', 'feature-request-system' ),
			'missing-feature'  => __( 'It is missing a feature I need', 'feature-request-system' ),
			'better-plugin'    => __( 'I found a better plugin', 'feature-request-system' ),
			'no-longer-needed' => __( 'I no longer need it', 'feature-request-system' ),
			'broke-site'       => __( 'It broke or slowed down my site', 'feature-request-system' ),
		];
	}

	/**
	 * Whether to ask the current user: they can deactivate plugins and haven't put the question off.
	 */
	private static function should_ask(): bool {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}

		return (int) get_user_meta( get_current_user_id(), self::META_SNOOZE, true ) < time();
	}

	/**
	 * Whether the current screen is the Plugins screen, of a site or of the network.
	 */
	private static function on_plugins_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'plugins' === $screen->base;
	}

	/**
	 * Loads the script and styles on the Plugins screen.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'plugins.php' !== $hook || ! self::should_ask() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, Plugin::url( 'assets/admin/deactivation-feedback.css' ), [], Plugin::VERSION );
		wp_enqueue_script( self::HANDLE, Plugin::url( 'assets/admin/deactivation-feedback.js' ), [ 'wp-i18n' ], Plugin::VERSION, true );
		wp_set_script_translations( self::HANDLE, 'feature-request-system', Plugin::path( 'languages' ) );
		wp_add_inline_script(
			self::HANDLE,
			'window.mwtfrsDeactivationFeedback = ' . wp_json_encode(
				[
					'plugin'  => plugin_basename( Plugin::path( 'feature-request-system.php' ) ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::ACTION,
					'nonce'   => wp_create_nonce( self::ACTION ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Prints the dialog at the end of the Plugins screen.
	 */
	public static function render(): void {
		if ( ! self::on_plugins_screen() || ! self::should_ask() ) {
			return;
		}

		$reasons = self::reasons() + [
			self::TEMPORARY => __( 'It is temporary, I am troubleshooting an issue', 'feature-request-system' ),
			self::OTHER     => __( 'Other', 'feature-request-system' ),
		];
		$new_tab = '<span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'feature-request-system' ) . '</span>';
		?>
		<dialog id="<?php echo esc_attr( self::ID ); ?>" class="mwtfrs-df" aria-labelledby="<?php echo esc_attr( self::ID ); ?>-title">
			<form method="dialog">
				<div class="mwtfrs-df-header"><?php esc_html_e( 'Quick feedback', 'feature-request-system' ); ?></div>
				<div class="mwtfrs-df-body">
					<fieldset>
						<legend id="<?php echo esc_attr( self::ID ); ?>-title"><?php esc_html_e( 'If you have a moment, tell us why you are deactivating Feature Request System:', 'feature-request-system' ); ?></legend>
						<?php foreach ( $reasons as $reason => $label ) : ?>
							<div class="mwtfrs-df-reason" data-reason="<?php echo esc_attr( $reason ); ?>">
								<label><input type="radio" name="reason" value="<?php echo esc_attr( $reason ); ?>"> <?php echo esc_html( $label ); ?></label>
								<div class="mwtfrs-df-more" hidden><?php self::render_more( $reason, $new_tab ); ?></div>
							</div>
						<?php endforeach; ?>
					</fieldset>
					<p class="mwtfrs-df-note">
						<?php esc_html_e( 'Sending shares your answer, the plugin, WordPress and PHP versions and the site language with master-webtools.com. Not your site address, your email or your requests.', 'feature-request-system' ); ?>
						<a href="<?php echo esc_url( self::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'feature-request-system' ); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built. ?></a>
					</p>
				</div>
				<div class="mwtfrs-df-footer">
					<label class="mwtfrs-df-snooze" hidden><input type="checkbox" name="snooze" value="1"> <?php esc_html_e( 'Don\'t ask again for 24 hours', 'feature-request-system' ); ?></label>
					<button type="submit" class="button mwtfrs-df-submit"><?php esc_html_e( 'Skip & Deactivate', 'feature-request-system' ); ?></button>
					<button type="button" class="button mwtfrs-df-cancel"><?php esc_html_e( 'Cancel', 'feature-request-system' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	/**
	 * Prints what opens under an answer: a link to the support forum, or a text field.
	 *
	 * @param string $reason  The answer's code.
	 * @param string $new_tab Escaped "(opens in a new tab)" for screen readers.
	 */
	private static function render_more( string $reason, string $new_tab ): void {
		if ( 'stopped-working' === $reason || 'broke-site' === $reason ) {
			?>
			<div class="mwtfrs-df-support">
				<p><?php esc_html_e( 'Sorry about that. Ask in the support forum and we will help you get it working.', 'feature-request-system' ); ?></p>
				<a class="button button-small" href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get help in the support forum', 'feature-request-system' ); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built. ?></a>
			</div>
			<?php
			return;
		}

		$fields = [
			'missing-feature' => __( 'Which feature?', 'feature-request-system' ),
			'better-plugin'   => __( 'What is the plugin\'s name?', 'feature-request-system' ),
			self::OTHER       => __( 'Tell us the reason, so we can improve the plugin.', 'feature-request-system' ),
		];
		if ( ! isset( $fields[ $reason ] ) ) {
			return;
		}

		$id = self::ID . '-details-' . $reason;
		?>
		<div class="mwtfrs-df-details">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $fields[ $reason ] ); ?></label>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" name="details" maxlength="<?php echo esc_attr( (string) self::MAX_DETAILS ); ?>" disabled>
		</div>
		<?php
	}

	/**
	 * Takes the answer from the dialog and sends it on. The page deactivates the plugin
	 * whatever this returns, so it waits a few seconds at most and reports no failures.
	 */
	public static function handle_answer(): void {
		check_ajax_referer( self::ACTION );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}

		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';

		if ( self::TEMPORARY === $reason ) {
			if ( ! empty( $_POST['snooze'] ) ) {
				update_user_meta( get_current_user_id(), self::META_SNOOZE, time() + DAY_IN_SECONDS );
			}
			wp_send_json_success();
		}

		$details = isset( $_POST['details'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) ) : '';
		$details = function_exists( 'mb_substr' ) ? mb_substr( $details, 0, self::MAX_DETAILS ) : substr( $details, 0, self::MAX_DETAILS );

		if ( ! array_key_exists( $reason, self::reasons() ) && ! ( self::OTHER === $reason && '' !== $details ) ) {
			wp_send_json_error( null, 400 );
		}

		wp_remote_post(
			self::FEEDBACK_URL,
			[
				'timeout'    => 3,
				// WordPress's own user agent names the site, which this doesn't send.
				'user-agent' => 'FeatureRequestSystem/' . Plugin::VERSION,
				'headers'    => [ 'Content-Type' => 'application/json' ],
				'body'       => wp_json_encode(
					[
						'plugin'         => self::SLUG,
						'reason'         => $reason,
						'details'        => $details,
						'plugin_version' => Plugin::VERSION,
						'wp_version'     => get_bloginfo( 'version' ),
						'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
						'locale'         => get_locale(),
					]
				),
			]
		);

		wp_send_json_success();
	}
}
