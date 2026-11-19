<?php
/**
 * Unit tests for Picker (plain PHP, no WordPress needed).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Tests;

use PHPUnit\Framework\TestCase;
use PulseQuestion\Picker;

final class PickerTest extends TestCase {

	public function testNextApprovedInQueueOrder(): void {
		$this->assertSame( array( 'id' => 9, 'reused' => false ), Picker::choose( array( 9, 4, 7 ), array( 1 => '2026-11-01' ), 1 ) );
	}

	public function testReusesTheOneAskedLongestAgo(): void {
		$asked = array(
			1 => '2026-11-10',
			2 => '2026-10-02',
			3 => '2026-11-15',
		);
		$this->assertSame( array( 'id' => 2, 'reused' => true ), Picker::choose( array(), $asked, 3 ) );
	}

	public function testNeverTheSameQuestionTwiceInARow(): void {
		$asked = array(
			1 => '2026-11-18',
			2 => '2026-11-19',
		);
		// 1 was asked longest ago but is today's question: ask 2.
		$this->assertSame( 2, Picker::choose( array(), $asked, 1 )['id'] );
	}

	public function testOnlyOneQuestionIsAskedAgain(): void {
		$this->assertSame( array( 'id' => 5, 'reused' => true ), Picker::choose( array(), array( 5 => '2026-11-18' ), 5 ) );
	}

	public function testTiesGoToTheOlderQuestion(): void {
		$this->assertSame( 3, Picker::choose( array(), array( 8 => '2026-11-01', 3 => '2026-11-01' ) )['id'] );
	}

	public function testNothingAtAll(): void {
		$this->assertNull( Picker::choose( array(), array() ) );
	}

	public function testDayWithSwitchTime(): void {
		$tz = new \DateTimeZone( 'America/Denver' );
		$this->assertSame( '2026-11-19', Picker::day( new \DateTimeImmutable( '2026-11-19 00:00', $tz ) ) );
		$this->assertSame( '2026-11-18', Picker::day( new \DateTimeImmutable( '2026-11-19 05:59', $tz ), '06:00' ) );
		$this->assertSame( '2026-11-19', Picker::day( new \DateTimeImmutable( '2026-11-19 06:00', $tz ), '06:00' ) );
		$this->assertSame( '2026-12-31', Picker::day( new \DateTimeImmutable( '2027-01-01 03:00', $tz ), '04:30' ) );
		$this->assertSame( '2026-11-19', Picker::day( new \DateTimeImmutable( '2026-11-19 03:00', $tz ), 'nonsense' ) );
	}
}
