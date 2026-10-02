<?php
/**
 * Base class for integration tests.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Tests\Integration;

use ActionScheduler;
use ActionScheduler_Action;
use ActionScheduler_Store;
use FeatureRequestSystem\Capabilities;
use FeatureRequestSystem\Content\BoardTaxonomy;
use FeatureRequestSystem\Content\RequestPostType;
use FeatureRequestSystem\Content\RequestTerms;
use FeatureRequestSystem\Content\StatusTaxonomy;
use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Submission\SpamGuard;
use WP_UnitTest_Factory;
use WP_UnitTestCase;

/**
 * Runs each test in a rolled-back transaction against a site where the plugin is installed.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Seeds the default board and statuses for the class.
	 *
	 * The WordPress test suite deletes all terms after each test class, so the terms
	 * seeded when the suite booted are gone by the second class. Installing again
	 * recreates them, as it would on a site whose default terms were lost.
	 *
	 * @param WP_UnitTest_Factory $factory Test data factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		Installer::install();
	}

	/**
	 * Restores plugin capabilities in memory.
	 *
	 * The database is rolled back after each test, but WordPress keeps roles in memory
	 * too, so a test that removes capabilities would otherwise leak into the next one.
	 */
	public function tear_down(): void {
		Capabilities::grant();
		parent::tear_down();
	}

	/**
	 * Creates a published request.
	 *
	 * @param array<string, mixed> $args Post fields to override.
	 */
	protected function create_request( array $args = [] ): int {
		return self::factory()->post->create(
			array_merge(
				[
					'post_type'   => RequestPostType::NAME,
					'post_status' => 'publish',
				],
				$args
			)
		);
	}

	/**
	 * Returns the ID of a seeded status.
	 *
	 * @param string $slug Status slug, such as "planned".
	 */
	protected function status( string $slug ): int {
		return (int) get_term_by( 'slug', $slug, StatusTaxonomy::NAME )->term_id;
	}

	/**
	 * Returns the IDs of a request's terms in one taxonomy.
	 *
	 * @param int    $post_id  Request ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	protected function term_ids( int $post_id, string $taxonomy ): array {
		return array_map( 'intval', wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] ) );
	}

	/**
	 * Returns the ID of the default board.
	 */
	protected function default_board(): int {
		return RequestTerms::default_term( BoardTaxonomy::NAME );
	}

	/**
	 * Makes new users follow a request.
	 *
	 * @param int    $request_id Request ID.
	 * @param int    $count      How many users.
	 * @param string $source     Why they follow it, one of SubscriptionRepository's SOURCE constants.
	 * @return int[] The users' IDs, in the order they started following.
	 */
	protected function create_followers( int $request_id, int $count, string $source = SubscriptionRepository::SOURCE_FOLLOW ): array {
		$users = self::factory()->user->create_many( $count );
		foreach ( $users as $user ) {
			SubscriptionRepository::add( $request_id, $user, $source );
		}

		return $users;
	}

	/**
	 * Returns the background actions that wait to run a hook, oldest first.
	 *
	 * @param string $hook Hook the actions run.
	 * @return ActionScheduler_Action[] Actions by their ID.
	 */
	protected function waiting_actions( string $hook ): array {
		return as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			]
		);
	}

	/**
	 * Runs the background actions that wait to run a hook, as Action Scheduler does once they are due.
	 *
	 * @param string $hook Hook the actions run.
	 * @return int How many actions ran.
	 */
	protected function run_waiting_actions( string $hook ): int {
		$actions = $this->waiting_actions( $hook );
		foreach ( array_keys( $actions ) as $action_id ) {
			ActionScheduler::runner()->process_action( $action_id, 'tests' );
		}

		return count( $actions );
	}

	/**
	 * Returns the addresses that were emailed during the test, in the order the emails were sent.
	 *
	 * @return string[]
	 */
	protected function emailed(): array {
		$addresses = [];
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $email ) {
			$addresses[] = $email['to'][0][0];
		}

		return $addresses;
	}

	/**
	 * Fields of a valid submission, as the form sends them a while after the page loaded.
	 *
	 * @param array<string, mixed> $fields Fields to override.
	 * @return array<string, mixed>
	 */
	protected function submission( array $fields = [] ): array {
		return array_merge(
			[
				'title'                => 'Export to CSV',
				'description'          => 'For our accountant.',
				SpamGuard::FIELD_TRAP  => '',
				SpamGuard::FIELD_TOKEN => SpamGuard::token( time() - 30 ),
			],
			$fields
		);
	}
}
