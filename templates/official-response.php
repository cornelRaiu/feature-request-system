<?php
/**
 * The official response to a feature request, pinned below its description.
 *
 * To change the markup, copy this file to yourtheme/feature-request-system/official-response.php.
 *
 * @package FeatureRequestSystem
 *
 * @var array<string, mixed> $args The response, prepared by FeatureRequestSystem\Frontend\RequestView. "text" is the
 *                                 comment's text as WordPress formats it in the comment list.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="mwtfrs-official-response" aria-labelledby="<?php echo esc_attr( $args['id'] ); ?>">
	<h2 class="mwtfrs-official-response__title" id="<?php echo esc_attr( $args['id'] ); ?>"><?php esc_html_e( 'Official response', 'feature-request-system' ); ?></h2>
	<div class="mwtfrs-official-response__text">
		<?php echo wp_kses_post( $args['text'] ); ?>
	</div>
	<p class="mwtfrs-official-response__meta">
		<?php echo esc_html( $args['author'] ); ?>,
		<a href="<?php echo esc_url( $args['url'] ); ?>"><time datetime="<?php echo esc_attr( $args['datetime'] ); ?>"><?php echo esc_html( $args['date'] ); ?></time></a>
		<?php if ( '' !== $args['remove_url'] ) : ?>
			<?php // Only moderators get this link. ?>
			<a class="mwtfrs-official-response__remove" href="<?php echo esc_url( $args['remove_url'] ); ?>"><?php esc_html_e( 'Remove official mark', 'feature-request-system' ); ?></a>
		<?php endif; ?>
	</p>
</section>
