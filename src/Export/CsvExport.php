<?php
/**
 * CSV export of requests and their voters.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Export;

use FeatureRequestSystem\Admin\RequestList;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\CategoryTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Notifications\Mailer;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Voting\VoteCount;
use FeatureRequestSystem\Voting\VoteRepository;
use WP_Post;
use WP_Query;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the requests that the admin list shows, or their voters, as CSV.
 *
 * Both exports read the list's own filters, search and sort order, and go through the
 * requests and the votes a page at a time, so a large board never sits in memory at once.
 * Submitters' and voters' email addresses are written only when the caller says so.
 */
final class CsvExport {

	/**
	 * Requests, and votes of one request, read per step.
	 */
	public const PAGE_SIZE = 500;

	/**
	 * Taxonomies the list can be filtered by. Their names are also the list's query arguments.
	 */
	private const TAXONOMIES = [ StatusTaxonomy::NAME, BoardTaxonomy::NAME, CategoryTaxonomy::NAME ];

	/**
	 * Sort orders of the list that the export follows. Anything else sorts by date.
	 */
	private const ORDERBY = [ 'title', 'date', 'modified', 'comment_count', RequestList::COLUMN_VOTES ];

	/**
	 * Characters that make a spreadsheet read a cell as a formula, or that some read past first.
	 */
	private const FORMULA_START = "=+-@\t\r";

	/**
	 * Reads the list's filters, search and sort order from its query arguments.
	 *
	 * Values that the list would not accept are dropped, as the list drops them.
	 *
	 * @param array<string, mixed> $input Query arguments, as in the address of the list.
	 * @return array<string, string>
	 */
	public static function filters( array $input ): array {
		$text = static function ( string $key ) use ( $input ): string {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		};

		$post_status = sanitize_key( $text( 'post_status' ) );
		$orderby     = sanitize_key( $text( 'orderby' ) );
		$order       = strtolower( $text( 'order' ) );

		$filters = [
			'post_status' => in_array( $post_status, get_post_stati(), true ) ? $post_status : '',
			's'           => sanitize_text_field( $text( 's' ) ),
			// The list's months dropdown sends 0 for every month.
			'm'           => preg_match( '/^\d{4}(\d{2})?$/', $text( 'm' ) ) ? $text( 'm' ) : '',
			'orderby'     => in_array( $orderby, self::ORDERBY, true ) ? $orderby : '',
			'order'       => in_array( $order, [ 'asc', 'desc' ], true ) ? $order : '',
		];
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$filters[ $taxonomy ] = sanitize_title( $text( $taxonomy ) );
		}

		return $filters;
	}

	/**
	 * Writes the requests, one row each, after a row of column names.
	 *
	 * @param array<string, string> $filters     Filters from filters().
	 * @param bool                  $with_emails Whether to add who submitted each request, with their email.
	 * @param callable              $write       Called with each piece of the file, in order.
	 * @phpstan-param callable(string): void $write
	 * @return int How many requests were written.
	 */
	public static function requests( array $filters, bool $with_emails, callable $write ): int {
		$columns = [
			__( 'ID', 'feature-request-system' ),
			__( 'Title', 'feature-request-system' ),
			__( 'Status', 'feature-request-system' ),
			__( 'Board', 'feature-request-system' ),
			__( 'Categories', 'feature-request-system' ),
			__( 'Votes', 'feature-request-system' ),
			__( 'Followers', 'feature-request-system' ),
			__( 'Date', 'feature-request-system' ),
			__( 'Link', 'feature-request-system' ),
		];
		if ( $with_emails ) {
			$columns[] = __( 'Submitter', 'feature-request-system' );
			$columns[] = __( 'Submitter email', 'feature-request-system' );
		}
		$write( self::line( $columns ) );

		return self::each_page(
			$filters,
			static function ( array $requests ) use ( $with_emails, $write ): void {
				$followers = SubscriptionRepository::counts( wp_list_pluck( $requests, 'ID' ) );
				$lines     = '';
				foreach ( $requests as $request ) {
					$cells = [
						$request->ID,
						Mailer::plain( $request->post_title ),
						self::term_name( RequestTerms::term_of( $request->ID, StatusTaxonomy::NAME ) ),
						self::term_name( RequestTerms::term_of( $request->ID, BoardTaxonomy::NAME ) ),
						self::categories( $request->ID ),
						VoteCount::get( $request->ID ),
						$followers[ $request->ID ] ?? 0,
						$request->post_date,
						'publish' === $request->post_status ? (string) get_permalink( $request ) : '',
					];
					if ( $with_emails ) {
						$cells = array_merge( $cells, self::submitter( $request->ID ) );
					}
					$lines .= self::line( $cells );
				}
				$write( $lines );
			}
		);
	}

	/**
	 * Writes the voters of the requests, one row per vote, after a row of column names.
	 *
	 * Votes are grouped by request, in the order of the list, and come oldest first.
	 *
	 * @param array<string, string> $filters     Filters from filters().
	 * @param bool                  $with_emails Whether to add the email of each voter who has an account.
	 * @param callable              $write       Called with each piece of the file, in order.
	 * @phpstan-param callable(string): void $write
	 * @return int How many votes were written.
	 */
	public static function voters( array $filters, bool $with_emails, callable $write ): int {
		$columns = [
			__( 'Request ID', 'feature-request-system' ),
			__( 'Request', 'feature-request-system' ),
			__( 'Voter', 'feature-request-system' ),
		];
		if ( $with_emails ) {
			$columns[] = __( 'Email', 'feature-request-system' );
		}
		$columns[] = __( 'Voted on', 'feature-request-system' );
		$write( self::line( $columns ) );

		$written = 0;
		self::each_page(
			$filters,
			static function ( array $requests ) use ( $with_emails, $write, &$written ): void {
				foreach ( $requests as $request ) {
					$title  = Mailer::plain( $request->post_title );
					$offset = 0;
					do {
						$votes = VoteRepository::of_request( $request->ID, self::PAGE_SIZE, $offset );
						cache_users( array_filter( wp_list_pluck( $votes, 'user_id' ) ) );

						$lines = '';
						foreach ( $votes as $vote ) {
							$user  = $vote['user_id'] ? get_userdata( $vote['user_id'] ) : false;
							$cells = [ $request->ID, $title, self::voter_name( $vote['user_id'], $user ) ];
							if ( $with_emails ) {
								$cells[] = $user ? $user->user_email : '';
							}
							$cells[] = get_date_from_gmt( $vote['created_at'] );
							$lines  .= self::line( $cells );
						}
						if ( '' !== $lines ) {
							$write( $lines );
						}

						$fetched  = count( $votes );
						$written += $fetched;
						$offset  += self::PAGE_SIZE;
					} while ( self::PAGE_SIZE === $fetched );
				}
			}
		);

		return $written;
	}

	/**
	 * Turns cells into one line of CSV.
	 *
	 * A cell that a spreadsheet would run as a formula gets a leading apostrophe, which
	 * makes the spreadsheet show it as text. A cell that holds a comma, a quote or a line
	 * break is quoted, with its quotes doubled.
	 *
	 * @param array<int, int|string> $cells Values, in column order.
	 */
	public static function line( array $cells ): string {
		$line = [];
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && false !== strpos( self::FORMULA_START, $cell[0] ) ) {
				$cell = "'" . $cell;
			}
			$line[] = preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}

		return implode( ',', $line ) . "\r\n";
	}

	/**
	 * Calls back with each page of the requests that match the filters, in the list's order.
	 *
	 * @param array<string, string> $filters  Filters from filters().
	 * @param callable              $callback Called with each page of requests.
	 * @phpstan-param callable(WP_Post[]): void $callback
	 * @return int How many requests there were.
	 */
	private static function each_page( array $filters, callable $callback ): int {
		$total = 0;
		$page  = 1;
		do {
			$query    = new WP_Query( self::query( $filters, $page ) );
			$requests = array_values( array_filter( $query->posts, [ self::class, 'is_post' ] ) );
			$fetched  = count( $query->posts );
			if ( $requests ) {
				$callback( $requests );
			}
			$total += $fetched;
			++$page;
		} while ( self::PAGE_SIZE === $fetched );

		return $total;
	}

	/**
	 * Builds the query for one page, as the list builds its own (see wp_edit_posts_query()).
	 *
	 * @param array<string, string> $filters Filters from filters().
	 * @param int                   $page    Page, from 1.
	 * @return array<string, mixed>
	 */
	private static function query( array $filters, int $page ): array {
		$post_status = $filters['post_status'] ?? '';
		$orderby     = $filters['orderby'] ?? '';
		$order       = $filters['order'] ?? '';
		if ( '' === $orderby ) {
			$orderby = in_array( $post_status, [ 'pending', 'draft' ], true ) ? 'modified' : 'date';
		}
		if ( '' === $order ) {
			$order = 'pending' === $post_status ? 'asc' : 'desc';
		}
		$order = strtoupper( $order );

		$args = [
			'post_type'           => RequestPostType::NAME,
			// The list's "All" shows every status but the trash and those with a view of their own.
			'post_status'         => '' !== $post_status ? $post_status : get_post_stati( [ 'show_in_admin_all_list' => true ] ),
			'posts_per_page'      => self::PAGE_SIZE,
			'paged'               => $page,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			// The ID breaks ties, so that no request moves between pages while they are read.
			'orderby'             => RequestList::COLUMN_VOTES === $orderby
				? [
					'meta_value_num' => $order,
					'date'           => 'DESC',
					'ID'             => 'DESC',
				]
				: [
					$orderby => $order,
					'ID'     => $order,
				],
		];

		if ( RequestList::COLUMN_VOTES === $orderby ) {
			// Every request has this meta from creation, so none drop out of the export.
			$args['meta_key'] = RequestPostType::META_VOTES; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by votes, as the list does.
		}

		// A user exports what they may read. WP-CLI, without a user, exports every request.
		if ( is_user_logged_in() ) {
			$args['perm'] = 'readable';
		}

		if ( '' !== ( $filters['s'] ?? '' ) ) {
			$args['s'] = $filters['s'];
		}
		if ( '' !== ( $filters['m'] ?? '' ) ) {
			$args['m'] = $filters['m'];
		}

		$tax_query = [];
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( '' !== ( $filters[ $taxonomy ] ?? '' ) ) {
				$tax_query[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $filters[ $taxonomy ],
				];
			}
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The list's own filters.
		}

		return $args;
	}

	/**
	 * Whether a query result is a post. A query without "fields" returns nothing else.
	 *
	 * @param mixed $item Item of a query's posts.
	 * @phpstan-assert-if-true WP_Post $item
	 */
	private static function is_post( $item ): bool {
		return $item instanceof WP_Post;
	}

	/**
	 * Returns a term's name as plain text, or '' when there is no term.
	 *
	 * @param WP_Term|null $term Term.
	 */
	private static function term_name( ?WP_Term $term ): string {
		return $term ? Mailer::plain( $term->name ) : '';
	}

	/**
	 * Returns the names of a request's categories, separated by commas.
	 *
	 * @param int $request_id Request ID.
	 */
	private static function categories( int $request_id ): string {
		$terms = get_the_terms( $request_id, CategoryTaxonomy::NAME );

		return is_array( $terms ) ? implode( ', ', array_map( [ self::class, 'term_name' ], $terms ) ) : '';
	}

	/**
	 * Returns who submitted a request and their email: a user's, or what a visitor gave.
	 *
	 * @param int $request_id Request ID.
	 * @return array{0: string, 1: string} Name and email, each '' when unknown.
	 */
	private static function submitter( int $request_id ): array {
		$user_id = (int) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_ID, true );
		if ( $user_id ) {
			$user = get_userdata( $user_id );

			return $user ? [ Mailer::plain( $user->display_name ), $user->user_email ] : [ __( 'A user who has since been deleted', 'feature-request-system' ), '' ];
		}

		return [
			Mailer::plain( (string) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_NAME, true ) ),
			(string) get_post_meta( $request_id, RequestPostType::META_SUBMITTER_EMAIL, true ),
		];
	}

	/**
	 * Returns how a voter is named: a user by their name, anyone else as a visitor.
	 *
	 * @param int           $user_id User ID from the vote, 0 for a visitor.
	 * @param WP_User|false $user    The user, false when there is none.
	 */
	private static function voter_name( int $user_id, $user ): string {
		if ( ! $user_id ) {
			return __( 'Visitor', 'feature-request-system' );
		}

		return $user ? Mailer::plain( $user->display_name ) : __( 'A user who has since been deleted', 'feature-request-system' );
	}
}
