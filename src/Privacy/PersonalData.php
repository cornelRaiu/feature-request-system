<?php
/**
 * Personal data export and erasure.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Privacy;

use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Notifications\Mailer;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Notifications\Subscriptions;
use FeatureRequestSystem\Voting\VoteRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the plugin's data to WordPress's privacy tools, under Tools → Export Personal Data
 * and Tools → Erase Personal Data.
 *
 * A person is found by their email address: as the user with that address, and as a
 * visitor who gave it when they suggested a feature without an account. Visitors who
 * voted without an account cannot be found: their votes hang on a random cookie.
 *
 * Erasing keeps what the site needs and cuts its tie to the person. Requests stay,
 * without their submitter. Votes stay, so counts stay correct, but no longer say whose
 * they are. What the person follows is deleted.
 */
final class PersonalData {

	/**
	 * Key of the plugin's exporter and eraser in WordPress's lists.
	 */
	private const KEY = 'feature-request-system';

	/**
	 * Items handled per step. WordPress calls the exporter and the eraser page by page.
	 */
	private const PER_PAGE = 100;

	/**
	 * Post statuses a suggested request can be in. Everything but automatic drafts.
	 */
	private const STATUSES = [ 'publish', 'pending', 'draft', 'future', 'private', 'trash' ];

	/**
	 * Adds the exporter and the eraser to WordPress's privacy tools.
	 */
	public static function add_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters[ self::KEY ] = [
			'exporter_friendly_name' => __( 'Feature Request System', 'feature-request-system' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers so far.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers[ self::KEY ] = [
			'eraser_friendly_name' => __( 'Feature Request System', 'feature-request-system' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Returns one page of what the plugin stores about a person.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page of the export, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$user_id = $user ? $user->ID : 0;
		$offset  = ( max( 1, $page ) - 1 ) * self::PER_PAGE;

		$requests = self::suggested_requests( $user_id, $email, $offset );
		$votes    = $user_id ? VoteRepository::of_user( $user_id, self::PER_PAGE, $offset ) : [];
		$follows  = $user_id ? SubscriptionRepository::of_user( $user_id, self::PER_PAGE, $offset ) : [];
		$items    = [];

		foreach ( $requests as $request ) {
			$items[] = self::item(
				'mwtfrs-requests',
				__( 'Feature requests', 'feature-request-system' ),
				__( 'The feature requests this person suggested.', 'feature-request-system' ),
				'request-' . $request->ID,
				self::request_fields( $request )
			);
		}

		foreach ( $votes as $vote ) {
			$items[] = self::item(
				'mwtfrs-votes',
				__( 'Feature request votes', 'feature-request-system' ),
				__( 'The feature requests this user voted for.', 'feature-request-system' ),
				'vote-' . $vote['request_id'],
				array_merge(
					self::named_request( $vote['request_id'] ),
					[ __( 'Voted on', 'feature-request-system' ) => self::date( $vote['created_at'] ) ]
				)
			);
		}

		foreach ( $follows as $follow ) {
			$items[] = self::item(
				'mwtfrs-follows',
				__( 'Followed feature requests', 'feature-request-system' ),
				__( 'The feature requests this user follows, and gets emails about.', 'feature-request-system' ),
				'follow-' . $follow['request_id'],
				array_merge(
					self::named_request( $follow['request_id'] ),
					[
						__( 'Following since', 'feature-request-system' ) => self::date( $follow['created_at'] ),
						__( 'Because', 'feature-request-system' ) => self::reason( $follow['source'] ),
					]
				)
			);
		}

		// The one choice the plugin stores for a user goes out once, with the first page.
		if ( 1 === $page && $user_id && ! Subscriptions::wants_automatic( $user_id ) ) {
			$items[] = self::item(
				'mwtfrs-choices',
				__( 'Feature request choices', 'feature-request-system' ),
				'',
				'choices-' . $user_id,
				[ __( 'Follows the requests they vote for or suggest', 'feature-request-system' ) => __( 'No', 'feature-request-system' ) ]
			);
		}

		return [
			'data' => $items,
			'done' => max( count( $requests ), count( $votes ), count( $follows ) ) < self::PER_PAGE,
		];
	}

	/**
	 * Erases one page of what the plugin stores about a person.
	 *
	 * @param string $email Email address of the person.
	 * @param int    $page  Page of the erasure, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$user     = get_user_by( 'email', $email );
		$user_id  = $user ? $user->ID : 0;
		$removed  = 0;
		$messages = [];

		// Always the first batch: erasing a request's submitter takes it out of this list.
		$requests = self::suggested_requests( $user_id, $email, 0 );
		foreach ( $requests as $request ) {
			delete_post_meta( $request->ID, RequestPostType::META_SUBMITTER_ID );
			delete_post_meta( $request->ID, RequestPostType::META_SUBMITTER_NAME );
			delete_post_meta( $request->ID, RequestPostType::META_SUBMITTER_EMAIL );
			++$removed;
		}
		if ( $requests ) {
			$messages[] = __( 'Feature requests this person suggested were kept, without the record of who suggested them.', 'feature-request-system' );
		}

		if ( $user_id && 1 === $page ) {
			$votes = VoteRepository::anonymise_user( $user_id );
			if ( $votes ) {
				$messages[] = __( 'Votes for feature requests were kept, so that vote counts stay correct, without the record of whose they are.', 'feature-request-system' );
			}

			$removed += $votes + SubscriptionRepository::remove_user( $user_id );
			$removed += (int) delete_user_meta( $user_id, Subscriptions::META_AUTO_FOLLOW );
		}

		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => count( $requests ) < self::PER_PAGE,
		];
	}

	/**
	 * Returns the requests a person suggested: as a user, or as a visitor who gave this email address.
	 *
	 * @param int    $user_id User ID, or 0 when no user has the email address.
	 * @param string $email   Email address.
	 * @param int    $offset  Requests to skip.
	 * @return WP_Post[]
	 */
	private static function suggested_requests( int $user_id, string $email, int $offset ): array {
		$by = [
			[
				'key'   => RequestPostType::META_SUBMITTER_EMAIL,
				'value' => $email,
			],
		];
		if ( $user_id ) {
			$by[] = [
				'key'   => RequestPostType::META_SUBMITTER_ID,
				'value' => $user_id,
			];
		}

		return get_posts(
			[
				'post_type'      => RequestPostType::NAME,
				'post_status'    => self::STATUSES,
				'posts_per_page' => self::PER_PAGE,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array_merge( [ 'relation' => 'OR' ], $by ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs only when an administrator exports or erases one person's data.
			]
		);
	}

	/**
	 * Returns what is exported about a request the person suggested.
	 *
	 * @param WP_Post $request Request.
	 * @return array<string, string>
	 */
	private static function request_fields( WP_Post $request ): array {
		$status = RequestTerms::term_of( $request->ID, StatusTaxonomy::NAME );

		return self::without_empty(
			[
				__( 'Title', 'feature-request-system' )   => Mailer::plain( $request->post_title ),
				__( 'Details', 'feature-request-system' ) => Mailer::plain( $request->post_content ),
				__( 'Suggested on', 'feature-request-system' ) => self::date( $request->post_date_gmt ),
				__( 'Status', 'feature-request-system' )  => $status ? Mailer::plain( $status->name ) : '',
				__( 'Link', 'feature-request-system' )    => 'publish' === $request->post_status ? (string) get_permalink( $request ) : '',
				__( 'Name given', 'feature-request-system' ) => (string) get_post_meta( $request->ID, RequestPostType::META_SUBMITTER_NAME, true ),
				__( 'Email given', 'feature-request-system' ) => (string) get_post_meta( $request->ID, RequestPostType::META_SUBMITTER_EMAIL, true ),
			]
		);
	}

	/**
	 * Returns the title of a request the person voted for or follows, and its link while it is public.
	 *
	 * @param int $request_id Request ID.
	 * @return array<string, string>
	 */
	private static function named_request( int $request_id ): array {
		$request = get_post( $request_id );
		if ( ! $request ) {
			return [ __( 'Request', 'feature-request-system' ) => __( 'A request that has since been deleted', 'feature-request-system' ) ];
		}

		return self::without_empty(
			[
				__( 'Request', 'feature-request-system' ) => Mailer::plain( $request->post_title ),
				__( 'Link', 'feature-request-system' )    => 'publish' === $request->post_status ? (string) get_permalink( $request ) : '',
			]
		);
	}

	/**
	 * Leaves out the fields that have no value.
	 *
	 * @param array<string, string> $fields Values by name.
	 * @return array<string, string>
	 */
	private static function without_empty( array $fields ): array {
		return array_filter(
			$fields,
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);
	}

	/**
	 * Builds one item of the export.
	 *
	 * @param string                $group_id    ID of the group the item belongs to.
	 * @param string                $group_label Name of the group.
	 * @param string                $description What the group holds.
	 * @param string                $item_id     ID of the item.
	 * @param array<string, string> $fields      Values by name.
	 * @return array<string, mixed>
	 */
	private static function item( string $group_id, string $group_label, string $description, string $item_id, array $fields ): array {
		$data = [];
		foreach ( $fields as $name => $value ) {
			$data[] = [
				'name'  => $name,
				'value' => $value,
			];
		}

		return [
			'group_id'          => $group_id,
			'group_label'       => $group_label,
			'group_description' => $description,
			'item_id'           => $item_id,
			'data'              => $data,
		];
	}

	/**
	 * Formats a stored UTC date and time for the export, in the site's time zone and format.
	 *
	 * @param string $mysql_gmt Date and time in UTC, as MySQL stores it.
	 */
	private static function date( string $mysql_gmt ): string {
		return (string) get_date_from_gmt( $mysql_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Says why the user follows a request.
	 *
	 * @param string $source One of SubscriptionRepository's SOURCE constants.
	 */
	private static function reason( string $source ): string {
		switch ( $source ) {
			case SubscriptionRepository::SOURCE_VOTE:
				return __( 'They voted for it', 'feature-request-system' );
			case SubscriptionRepository::SOURCE_AUTHOR:
				return __( 'They suggested it', 'feature-request-system' );
			default:
				return __( 'They chose to follow it', 'feature-request-system' );
		}
	}
}
