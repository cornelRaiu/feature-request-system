<?php
/**
 * Similar requests, to avoid duplicates.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Submission;

use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Voting\VoteCount;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Finds published requests that match what someone is typing, so they can vote for an
 * existing request instead of posting a duplicate.
 *
 * WordPress search needs every word to match, so "Add dark mode" would miss a request
 * titled "Dark mode". Here a title needs only one meaningful word in common, and titles
 * that share more words come first, then the most voted.
 */
final class SimilarRequests {

	/**
	 * Shortest text worth searching for, in characters.
	 */
	public const MIN_LENGTH = 3;

	/**
	 * Most requests to suggest.
	 */
	private const LIMIT = 5;

	/**
	 * Shortest word that counts, in characters.
	 */
	private const MIN_WORD_LENGTH = 3;

	/**
	 * Most words to search for.
	 */
	private const MAX_WORDS = 8;

	/**
	 * Query variable that carries the words to the SQL filters.
	 */
	private const QUERY_VAR = 'mwtfrs_similar_words';

	/**
	 * Returns published requests on a board whose titles share words with the text.
	 *
	 * @param int    $board_id Board term ID.
	 * @param string $text     What the visitor typed.
	 * @return array<int, array{id: int, title: string, url: string, label: string}>
	 */
	public static function find( int $board_id, string $text ): array {
		$words = mb_strlen( trim( $text ) ) < self::MIN_LENGTH ? [] : self::words( $text );
		if ( ! $words ) {
			return [];
		}

		add_filter( 'posts_search', [ self::class, 'search_sql' ], 10, 2 );
		add_filter( 'posts_orderby', [ self::class, 'orderby_sql' ], 10, 2 );

		$query = new WP_Query(
			[
				'post_type'           => RequestPostType::NAME,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => self::LIMIT,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Requests of one board.
					[
						'taxonomy' => BoardTaxonomy::NAME,
						'field'    => 'term_id',
						'terms'    => $board_id,
					],
				],
				// Every request has this meta from creation, so none drop out.
				'meta_key'            => RequestPostType::META_VOTES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Most voted first among equal matches.
				'orderby'             => 'meta_value_num',
				'order'               => 'DESC',
				self::QUERY_VAR       => $words,
			]
		);

		remove_filter( 'posts_search', [ self::class, 'search_sql' ] );
		remove_filter( 'posts_orderby', [ self::class, 'orderby_sql' ] );

		$matches = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$matches[] = self::describe( $post );
			}
		}

		return $matches;
	}

	/**
	 * Limits the query to titles that contain at least one of the words.
	 *
	 * @param string   $search Search SQL.
	 * @param WP_Query $query  Query being run.
	 */
	public static function search_sql( string $search, WP_Query $query ): string {
		$likes = self::title_likes( $query );

		return $likes ? ' AND ( ' . implode( ' OR ', $likes ) . ' )' : $search;
	}

	/**
	 * Puts titles that contain more of the words first.
	 *
	 * @param string   $orderby ORDER BY SQL.
	 * @param WP_Query $query   Query being run.
	 */
	public static function orderby_sql( string $orderby, WP_Query $query ): string {
		$likes = self::title_likes( $query );

		// In MySQL a LIKE comparison is 1 or 0, so the sum counts the words a title contains.
		return $likes ? '( ' . implode( ' + ', $likes ) . ' ) DESC, ' . $orderby : $orderby;
	}

	/**
	 * One "title contains the word" condition per word, for queries made by find().
	 *
	 * @param WP_Query $query Query being run.
	 * @return string[]
	 */
	private static function title_likes( WP_Query $query ): array {
		global $wpdb;

		$words = $query->get( self::QUERY_VAR );
		if ( ! is_array( $words ) ) {
			return [];
		}

		$likes = [];
		foreach ( $words as $word ) {
			// Parentheses because + binds tighter than LIKE when the conditions are added up.
			$likes[] = $wpdb->prepare( "( {$wpdb->posts}.post_title LIKE %s )", '%' . $wpdb->esc_like( (string) $word ) . '%' );
		}

		return $likes;
	}

	/**
	 * Splits text into the distinct words worth searching for.
	 *
	 * @param string $text What the visitor typed.
	 * @return string[]
	 */
	private static function words( string $text ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return [];
		}

		$stopwords = self::stopwords();
		$words     = array_filter(
			array_unique( $parts ),
			static function ( string $word ) use ( $stopwords ): bool {
				return mb_strlen( $word ) >= self::MIN_WORD_LENGTH && ! in_array( $word, $stopwords, true );
			}
		);

		return array_slice( array_values( $words ), 0, self::MAX_WORDS );
	}

	/**
	 * Common words that say nothing about what a request is for.
	 *
	 * @return string[]
	 */
	private static function stopwords(): array {
		/* translators: Comma-separated list of common words to ignore when looking for similar requests. Use common words of your language, in lowercase; they do not need to match the English list. */
		$list = __( 'able,about,add,adding,allow,also,and,any,are,but,can,could,feature,for,from,get,has,have,how,into,like,make,more,need,new,not,option,please,should,some,support,that,the,their,them,then,there,this,use,using,want,was,way,what,when,where,which,will,with,would,you,your', 'feature-request-system' );

		return array_filter( array_map( 'trim', explode( ',', mb_strtolower( $list ) ) ) );
	}

	/**
	 * Describes one request for the suggestions list.
	 *
	 * @param WP_Post $post Request.
	 * @return array{id: int, title: string, url: string, label: string}
	 */
	private static function describe( WP_Post $post ): array {
		$votes  = VoteCount::get( $post->ID );
		$status = RequestTerms::term_of( $post->ID, StatusTaxonomy::NAME );
		/* translators: %s: number of votes. */
		$label = sprintf( _n( '%s vote', '%s votes', $votes, 'feature-request-system' ), number_format_i18n( $votes ) );

		return [
			'id'    => $post->ID,
			'title' => self::plain( get_the_title( $post ) ),
			'url'   => (string) get_permalink( $post ),
			'label' => self::plain( $status ? $label . ' · ' . $status->name : $label ),
		];
	}

	/**
	 * Turns stored HTML text into plain text. The script shows these values with textContent,
	 * where entities such as &amp; would appear as typed.
	 *
	 * @param string $html Text that may contain entities.
	 */
	private static function plain( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}
}
