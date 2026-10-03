<?php
/**
 * One request as the board, a list and a card show it: its vote button, title, status
 * and the message after a vote. The board and the card add a summary and the categories.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/request-item.php.
 * Keep the data-wp-* attributes: they show what happened after a vote. The element around
 * it holds the request's data-wp-context, which board.php, request-list.php and
 * request-card.php provide. Every value is escaped here, at output. The vote button has
 * its own template, vote-button.php.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args The args of the template that shows the request. Of those, "item" is the request,
 *                                 prepared by FeatureRequestSystem\Frontend\RequestItem::prepare(), "title_tag" is the
 *                                 element of its title, and "compact" says whether to leave out the summary and the categories.
 */

defined( 'ABSPATH' ) || exit;

$mwtfrs_item       = $args['item'];
$mwtfrs_title_tag  = $args['title_tag'];
$mwtfrs_categories = $args['compact'] ? [] : $mwtfrs_item['categories'];
?>
<?php echo FeatureRequestSystem\Frontend\Template::render( 'vote-button', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The vote button template escapes every value. ?>

<div class="mwtfrs-request__body">
	<<?php echo tag_escape( $mwtfrs_title_tag ); ?> class="mwtfrs-request__title"><a href="<?php echo esc_url( $mwtfrs_item['url'] ); ?>"><?php echo esc_html( $mwtfrs_item['title'] ); ?></a></<?php echo tag_escape( $mwtfrs_title_tag ); ?>>

	<?php if ( ! $args['compact'] && '' !== $mwtfrs_item['excerpt'] ) : ?>
		<p class="mwtfrs-request__excerpt"><?php echo esc_html( $mwtfrs_item['excerpt'] ); ?></p>
	<?php endif; ?>

	<?php if ( $mwtfrs_item['status'] || $mwtfrs_categories ) : ?>
		<p class="mwtfrs-request__meta">
			<?php if ( $mwtfrs_item['status'] ) : ?>
				<span class="mwtfrs-status" style="--mwtfrs-status-color: <?php echo esc_attr( $mwtfrs_item['status']['color'] ); ?>"><?php echo esc_html( $mwtfrs_item['status']['name'] ); ?></span>
			<?php endif; ?>
			<?php foreach ( $mwtfrs_categories as $mwtfrs_category ) : ?>
				<span class="mwtfrs-category"><?php echo esc_html( $mwtfrs_category ); ?></span>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<p class="mwtfrs-request__message" role="status" data-wp-bind--hidden="!context.message">
		<span data-wp-text="context.message"><?php echo esc_html( $mwtfrs_item['context']['message'] ); ?></span>
		<a href="<?php echo esc_url( $args['login_url'] ); ?>" data-wp-bind--hidden="!context.needsLogin"><?php esc_html_e( 'Log in', 'feature-request-system' ); ?></a>
	</p>
</div>
