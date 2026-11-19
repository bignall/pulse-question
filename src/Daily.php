<?php
/**
 * The question of the day.
 *
 * When a new question day starts (at the switch time in Settings, in the site's timezone), the
 * next approved question is asked; when none are left, the one asked longest ago is asked again.
 * This happens on the first page view of the day or the hourly check, whichever comes first, so it
 * doesn't depend on WP-Cron running on time.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

final class Daily {

	/** array{id: int, day: string, reused: bool} for today's question. */
	public const OPTION    = 'pulse_question_today';
	public const CRON_HOOK = 'pulse_question_daily_check';
	private const LOCK     = 'pulse_question_pick_lock';

	public static function init(): void {
		add_action( self::CRON_HOOK, array( self::class, 'current' ) );
		add_action( 'template_redirect', array( self::class, 'current' ), 1 );
	}

	/** The question day it is now. */
	public static function day(): string {
		return Picker::day( new \DateTimeImmutable( 'now', wp_timezone() ), (string) Settings::get( 'switch_time' ) );
	}

	/**
	 * Today's question, picking it if the day has changed (or it was trashed or deleted).
	 *
	 * @return array{id: int, day: string, reused: bool}|null Null when there are no questions yet.
	 */
	public static function current(): ?array {
		$today = self::stored();
		$day   = self::day();
		if ( null !== $today && $today['day'] === $day && 'publish' === get_post_status( $today['id'] ) ) {
			return $today;
		}
		return self::pick( $day, $today['id'] ?? 0 );
	}

	/**
	 * Ask the next question now, for the rest of today (the "Ask the next one now" button).
	 *
	 * @param int $id A particular approved question, or 0 for the next in the queue.
	 * @return array{id: int, day: string, reused: bool}|null
	 */
	public static function askNow( int $id = 0 ): ?array {
		$stored = self::stored();
		return self::pick( self::day(), $stored['id'] ?? 0, $id );
	}

	/** @return array{id: int, day: string, reused: bool}|null */
	public static function stored(): ?array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) || empty( $saved['id'] ) ) {
			return null;
		}
		return array(
			'id'     => (int) $saved['id'],
			'day'    => (string) ( $saved['day'] ?? '' ),
			'reused' => ! empty( $saved['reused'] ),
		);
	}

	/**
	 * @return array{id: int, day: string, reused: bool}|null
	 */
	private static function pick( string $day, int $previous, int $wanted = 0 ): ?array {
		// One pick at a time: add_option fails if another request holds the lock.
		$lock = get_option( self::LOCK );
		if ( false !== $lock && (int) $lock < time() - MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK );
		}
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			return self::stored();
		}

		try {
			// Another request may have picked while this one waited.
			$fresh = self::stored();
			if ( 0 === $wanted && null !== $fresh && $fresh['day'] === $day && $fresh['id'] !== $previous && 'publish' === get_post_status( $fresh['id'] ) ) {
				return $fresh;
			}

			if ( $wanted > 0 && get_post_status( $wanted ) === Questions::STATUS_APPROVED ) {
				$choice = array(
					'id'     => $wanted,
					'reused' => false,
				);
			} else {
				$choice = Picker::choose( Questions::queue(), Questions::asked(), $previous );
			}
			if ( null === $choice ) {
				return null;
			}

			Questions::markAsked( $choice['id'], $day );
			$today = array(
				'id'     => $choice['id'],
				'day'    => $day,
				'reused' => $choice['reused'],
			);
			update_option( self::OPTION, $today, true );

			/**
			 * Fires when a new question of the day is chosen (for emails, social posts and so on).
			 *
			 * @param int    $id     The question.
			 * @param string $day    The day it's for (Y-m-d).
			 * @param bool   $reused Whether it was asked before.
			 */
			do_action( 'pulse_question_new_day', $choice['id'], $day, $choice['reused'] );
			return $today;
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** Days of questions left in the queue. */
	public static function daysLeft(): int {
		return count( Questions::queue() );
	}
}
