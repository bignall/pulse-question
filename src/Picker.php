<?php
/**
 * Which question is asked next, and which day it is for.
 *
 * Every approved question is asked once, in queue order. When none are left, the question asked
 * longest ago is asked again, so there is always a question of the day once one has been asked.
 *
 * Plain PHP, no WordPress, so it can be unit tested.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

final class Picker {

	/**
	 * Choose the next question.
	 *
	 * @param list<int>          $approved Approved, never-asked questions, in queue order.
	 * @param array<int, string> $asked    Asked questions => the day each was last asked (Y-m-d).
	 * @param int                $current  Today's question until now (0 for none): not asked twice in a row
	 *                                     unless it's the only one.
	 * @return array{id: int, reused: bool}|null Null when there are no questions at all.
	 */
	public static function choose( array $approved, array $asked, int $current = 0 ): ?array {
		if ( $approved ) {
			return array(
				'id'     => (int) $approved[0],
				'reused' => false,
			);
		}
		$candidates = $asked;
		if ( count( $candidates ) > 1 ) {
			unset( $candidates[ $current ] );
		}
		if ( ! $candidates ) {
			return null;
		}
		// Asked longest ago first; on the same day, the older question.
		uksort(
			$candidates,
			static fn ( int $a, int $b ): int => ( $candidates[ $a ] <=> $candidates[ $b ] ) ?: ( $a <=> $b )
		);
		return array(
			'id'     => (int) array_key_first( $candidates ),
			'reused' => true,
		);
	}

	/**
	 * The question day a moment belongs to. The question changes at $switch (H:i) each day, so
	 * with a 06:00 switch, 05:59 on the 20th still belongs to the 19th.
	 */
	public static function day( \DateTimeImmutable $now, string $switch = '00:00' ): string {
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $switch, $m ) ) {
			return $now->format( 'Y-m-d' );
		}
		$minutes = (int) $m[1] * 60 + (int) $m[2];
		return $now->modify( '-' . $minutes . ' minutes' )->format( 'Y-m-d' );
	}
}
