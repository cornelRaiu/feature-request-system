<?php
/**
 * A request, prepared for display.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Frontend;

use FeatureRequestSystem\Content\BoardSettings;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Voting\VoteCount;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers what the templates show about one request: on the board and on its own page.
 */
final class RequestItem {

	/**
	 * Prepares one request for a template.
	 *
	 * @param WP_Post                                                $post     Request.
	 * @param string                                                 $voting   Voting rule of the request's board, a BoardSettings value.
	 * @param array{request: int, message: string, login: bool}|null $notice   Notice from a vote without JavaScript.
	 * @param string                                                 $instance Unique prefix of the list or card that shows the request, or '' on the board and on the request's own page.
	 * @return array<string, mixed>
	 */
	public static function prepare( WP_Post $post, string $voting, ?array $notice, string $instance = '' ): array {
		$status     = RequestTerms::term_of( $post->ID, StatusTaxonomy::NAME );
		$categories = get_the_terms( $post->ID, CategoryTaxonomy::NAME );
		$summary    = '' !== $post->post_excerpt ? $post->post_excerpt : strip_shortcodes( $post->post_content );
		$votes      = VoteCount::get( $post->ID );
		$notice     = $notice && $notice['request'] === $post->ID ? $notice : null;
		$votable    = 'publish' === $post->post_status
			&& BoardSettings::NOBODY !== $voting
			&& ! ( $status && StatusTaxonomy::is_closed( $status ) );

		return [
			'id'          => $post->ID,
			'dom_id'      => self::dom_id( $post->ID, $instance ),
			'title'       => get_the_title( $post ),
			'url'         => (string) get_permalink( $post ),
			'excerpt'     => wp_trim_words( wp_strip_all_tags( $summary ), 30 ),
			'votes'       => $votes,
			'status'      => $status ? [
				'name'  => $status->name,
				'color' => StatusTaxonomy::color( $status ),
			] : null,
			'categories'  => is_array( $categories ) ? wp_list_pluck( $categories, 'name' ) : [],
			'votable'     => $votable,
			'guest_votes' => $votable && BoardSettings::ANYONE === $voting,
			'context'     => [
				'id'         => $post->ID,
				'votes'      => $votes,
				'voted'      => false,
				'busy'       => false,
				'message'    => $notice ? $notice['message'] : '',
				'needsLogin' => $notice ? $notice['login'] : false,
			],
		];
	}

	/**
	 * HTML id of a request's element, used as the anchor to return to after a vote.
	 *
	 * A page can show a request more than once: on a list, on a card, on the board. Each
	 * list and card puts its own prefix in the id, so that no two elements share one.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $instance   Unique prefix of the list or card, or '' on the board and on the request's own page.
	 */
	public static function dom_id( int $request_id, string $instance = '' ): string {
		return ( '' === $instance ? 'mwtfrs' : $instance ) . '-request-' . $request_id;
	}
}
