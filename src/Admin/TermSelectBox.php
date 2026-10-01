<?php
/**
 * Single-choice term box for the request edit screen.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Admin;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a dropdown box to the request edit screen for each taxonomy where a request has
 * exactly one term, in place of the boxes WordPress would add for several terms.
 */
final class TermSelectBox {

	/**
	 * Adds the boxes to the request edit screen.
	 */
	public static function add_hooks(): void {
		add_action( 'add_meta_boxes_' . RequestPostType::NAME, [ self::class, 'add' ] );
	}

	/**
	 * Registers a box for the board and one for the status.
	 */
	public static function add(): void {
		$boxes = [
			BoardTaxonomy::NAME  => __( 'Board', 'feature-request-system' ),
			// Not "Status": the Publish box uses that word for Published, Pending and so on.
			StatusTaxonomy::NAME => __( 'Request status', 'feature-request-system' ),
		];

		foreach ( $boxes as $taxonomy => $title ) {
			add_meta_box( $taxonomy . 'div', $title, [ self::class, 'render' ], RequestPostType::NAME, 'side', 'core', [ 'taxonomy' => $taxonomy ] );
		}
	}

	/**
	 * Prints the dropdown.
	 *
	 * The field posts tax_input[taxonomy][] like the core category box, so WordPress saves it
	 * with the post and checks the taxonomy's assign_terms capability.
	 *
	 * @param WP_Post                              $post Request being edited.
	 * @param array{args: array{taxonomy: string}} $box  Meta box arguments from WordPress.
	 */
	public static function render( WP_Post $post, array $box ): void {
		$taxonomy = get_taxonomy( $box['args']['taxonomy'] );
		if ( ! $taxonomy ) {
			return;
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy->name,
				'hide_empty' => false,
			]
		);
		$terms = is_array( $terms ) ? $terms : [];
		if ( StatusTaxonomy::NAME === $taxonomy->name ) {
			$terms = StatusTaxonomy::sort( $terms );
		}

		$assigned = wp_get_object_terms( $post->ID, $taxonomy->name, [ 'fields' => 'ids' ] );
		$selected = is_array( $assigned ) && $assigned ? (int) $assigned[0] : RequestTerms::default_term( $taxonomy->name );
		$field_id = $taxonomy->name . '-select';

		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label>',
			esc_attr( $field_id ),
			esc_html( $taxonomy->labels->singular_name )
		);
		printf(
			'<select name="%1$s" id="%2$s" class="widefat"%3$s>',
			esc_attr( 'tax_input[' . $taxonomy->name . '][]' ),
			esc_attr( $field_id ),
			disabled( ! current_user_can( $taxonomy->cap->assign_terms ), true, false )
		);
		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $term->term_id,
				selected( $selected, $term->term_id, false ),
				esc_html( $term->name )
			);
		}
		echo '</select>';
	}
}
