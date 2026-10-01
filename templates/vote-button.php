<?php
/**
 * Vote button of one request, on the board and on the request's own page.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/vote-button.php.
 * Keep the data-wp-* attributes and the form fields: they make voting work, with and
 * without JavaScript. The button reads the request's data-wp-context from an element
 * around it, which board.php and request.php provide.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args The args of the template that shows the button. Of those, "item" is the request,
 *                                 prepared by FeatureRequestSystem\Frontend\RequestItem::prepare(), and "vote" is the
 *                                 form's settings, from FeatureRequestSystem\Frontend\VoteForm::template_args().
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_item = $args['item'];
$mwtfrs_vote = $args['vote'];
?>
<?php if ( $mwtfrs_item['votable'] ) : ?>
	<form class="mwtfrs-request__vote" method="post" action="<?php echo esc_url( $mwtfrs_vote['url'] ); ?>" data-wp-on--submit="actions.toggle">
		<input type="hidden" name="action" value="<?php echo esc_attr( $mwtfrs_vote['action'] ); ?>">
		<input type="hidden" name="request_id" value="<?php echo (int) $mwtfrs_item['id']; ?>">
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $mwtfrs_vote['redirect_to'] ); ?>">
		<?php if ( $mwtfrs_vote['nonce'] ) : ?>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $mwtfrs_vote['nonce'] ); ?>">
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
