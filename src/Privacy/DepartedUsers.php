<?php
/**
 * Votes and subscriptions of users who leave.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Privacy;

use FeatureRequestSystem\Install\Installer;
use FeatureRequestSystem\Notifications\SubscriptionRepository;
use FeatureRequestSystem\Voting\VoteRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps votes and subscriptions in step with users who leave.
 *
 * When a user is deleted, what they followed is deleted (Subscriptions does that), and
 * their votes stay, so that counts stay correct, but without the tie to the account. A
 * database can give the ID of a deleted user to the next new user, who must not get the
 * votes with it.
 *
 * On a network of sites, each site has its own votes and subscriptions:
 *
 * - A user who is removed from one site no longer follows anything there. Their votes
 *   there stay theirs, because the account still exists.
 * - A user who is deleted from the network is handled on every site they belonged to.
 *   What they follow on other sites is deleted when the next email would go to them.
 */
final class DepartedUsers {

	/**
	 * Adds the hooks for users who are deleted or removed.
	 */
	public static function add_hooks(): void {
		add_action( 'deleted_user', [ self::class, 'forget_votes' ] );
		add_action( 'remove_user_from_blog', [ self::class, 'unfollow_on_site' ], 10, 2 );
		add_action( 'wpmu_delete_user', [ self::class, 'forget_votes_on_their_sites' ] );
	}

	/**
	 * Cuts the tie between a deleted user and their votes on this site.
	 *
	 * @param int $user_id ID of the deleted user.
	 */
	public static function forget_votes( int $user_id ): void {
		VoteRepository::anonymise_user( $user_id );
	}

	/**
	 * Ends what a user follows on a site of the network they are removed from.
	 *
	 * @param int $user_id User ID.
	 * @param int $site_id ID of the site.
	 */
	public static function unfollow_on_site( int $user_id, int $site_id ): void {
		self::on_site(
			$site_id,
			static function () use ( $user_id ): void {
				SubscriptionRepository::remove_user( $user_id );
			}
		);
	}

	/**
	 * Cuts the tie between a user who is about to be deleted from the network and their
	 * votes, on every site they belong to.
	 *
	 * @param int $user_id User ID.
	 */
	public static function forget_votes_on_their_sites( int $user_id ): void {
		foreach ( get_blogs_of_user( $user_id ) as $site ) {
			self::on_site(
				(int) $site->userblog_id,
				static function () use ( $user_id ): void {
					VoteRepository::anonymise_user( $user_id );
				}
			);
		}
	}

	/**
	 * Runs a task on a site of the network, when the plugin is set up there.
	 *
	 * @param int      $site_id ID of the site.
	 * @param callable $task    What to do there.
	 * @phpstan-param callable(): void $task
	 */
	private static function on_site( int $site_id, callable $task ): void {
		$elsewhere = is_multisite() && get_current_blog_id() !== $site_id;
		if ( $elsewhere ) {
			switch_to_blog( $site_id );
		}

		// A site where the plugin was never active has no tables.
		if ( get_option( Installer::OPTION_DB_VERSION ) ) {
			$task();
		}

		if ( $elsewhere ) {
			restore_current_blog();
		}
	}
}
