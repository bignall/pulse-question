<?php
/**
 * Keeps the queue topped up with questions written by Claude.
 *
 * On the hourly check: when the questions in the queue plus those waiting for approval cover
 * fewer days than the target in Settings, one batch is written. Questions waiting for approval
 * count, so unapproved questions don't pile up. At most one batch every 6 hours; after a failure
 * the wait doubles each time (up to 2 days) until a batch works again.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Claude;

use PulseQuestion\Daily;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class TopUp {

	public const WAIT     = 'pq_claude_wait';
	public const FAILURES = 'pq_claude_failures';

	public static function init(): void {
		add_action( Daily::CRON_HOOK, array( self::class, 'check' ), 30 );
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'claude_auto' ) && Generator::isConfigured();
	}

	/** Days of questions in hand: in the queue, plus waiting for approval. */
	public static function inHand(): int {
		return count( Questions::queue() ) + count( Questions::waiting() );
	}

	/** @return array{added: list<int>, skipped: int, model: string}|\WP_Error|null Null when nothing was needed. */
	public static function check(): array|\WP_Error|null {
		if ( ! self::enabled() || get_transient( self::WAIT ) ) {
			return null;
		}
		if ( self::inHand() >= (int) Settings::get( 'claude_target_days' ) ) {
			return null;
		}
		$failures = (int) get_option( self::FAILURES, 0 );
		$wait     = min( 2 * DAY_IN_SECONDS, 6 * HOUR_IN_SECONDS * ( 2 ** $failures ) );
		set_transient( self::WAIT, time() + $wait, $wait );

		$result = Generator::generate( (int) Settings::get( 'claude_batch' ), '', 'auto' );
		if ( is_wp_error( $result ) ) {
			update_option( self::FAILURES, $failures + 1, false );
			// The next try waits longer.
			$next = min( 2 * DAY_IN_SECONDS, 6 * HOUR_IN_SECONDS * ( 2 ** ( $failures + 1 ) ) );
			set_transient( self::WAIT, time() + $next, $next );
		} else {
			delete_option( self::FAILURES );
		}
		return $result;
	}

	/** When the next automatic batch may run (0 = now). */
	public static function nextAllowed(): int {
		$wait = get_transient( self::WAIT );
		return is_numeric( $wait ) ? (int) $wait : 0;
	}
}
