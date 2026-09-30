<?php
/**
 * WP-CLI commands.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Cli;

use FeatureRequestSystem\Voting\VoteCount;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance commands for Feature Request System.
 */
final class Commands {

	/**
	 * Top-level command name, as in `wp mwtfrs recount`.
	 */
	public const NAME = 'mwtfrs';

	/**
	 * Recounts every request's votes and fixes stored counts that are wrong or missing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mwtfrs recount
	 *
	 * @when after_wp_load
	 */
	public function recount(): void {
		$result = VoteCount::sync_all();

		WP_CLI::success(
			sprintf(
				'Checked %1$d %2$s and fixed %3$d %4$s.',
				$result['checked'],
				1 === $result['checked'] ? 'request' : 'requests',
				$result['fixed'],
				1 === $result['fixed'] ? 'vote count' : 'vote counts'
			)
		);
	}
}
