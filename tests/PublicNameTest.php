<?php
/**
 * Unit tests for Answers::publicName (plain PHP, no WordPress needed).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Tests;

use PHPUnit\Framework\TestCase;
use PulseQuestion\Answers\Answers;

final class PublicNameTest extends TestCase {

	public function testEmailShowsOnlyThePartBeforeTheAt(): void {
		$this->assertSame( 'rosina', Answers::publicName( 'rosina@example.co' ) );
		$this->assertSame( 'jo.smith+pq', Answers::publicName( ' jo.smith+pq@mail.example.com ' ) );
	}

	public function testOrdinaryNamesAreUnchanged(): void {
		$this->assertSame( 'Rosina', Answers::publicName( 'Rosina' ) );
		$this->assertSame( 'Ana @ home', Answers::publicName( 'Ana @ home' ) );
		$this->assertSame( '', Answers::publicName( '' ) );
	}
}
