<?php
/**
 * Background work.
 *
 * @package FeatureRequestSystem
 */

namespace FeatureRequestSystem\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Hands work to Action Scheduler, the library that runs it in the background. The emails
 * to a request's followers go through here, so that saving a request never waits for mail.
 */
final class Queue {

	/**
	 * Group of this plugin's actions, as shown under Tools → Scheduled Actions.
	 */
	public const GROUP = 'feature-request-system';

	/**
	 * Runs a hook once in the background, at the given time or soon after.
	 *
	 * @param int               $timestamp When to run it.
	 * @param string            $hook      Hook to run.
	 * @param array<int, mixed> $args      Arguments for the hook's callbacks.
	 */
	public static function schedule( int $timestamp, string $hook, array $args ): void {
		$schedule = static function () use ( $timestamp, $hook, $args ): void {
			as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		};

		// Action Scheduler is ready from init on. Work that is handed over earlier waits for it.
		if ( did_action( 'action_scheduler_init' ) ) {
			$schedule();
		} else {
			add_action( 'action_scheduler_init', $schedule );
		}
	}

	/**
	 * Cancels everything this plugin has waiting. Does nothing when Action Scheduler is not running.
	 */
	public static function cancel_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			as_unschedule_all_actions( '', [], self::GROUP );
		}
	}
}
