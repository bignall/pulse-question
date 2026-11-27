<?php
/**
 * Unit tests for Text::plain (plain PHP, no WordPress needed).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Tests;

use PHPUnit\Framework\TestCase;
use PulseQuestion\Text;

final class TextTest extends TestCase {

	public function testDecodesTexturizedQuotes(): void {
		$this->assertSame( "If you had to eat one country\u{2019}s food?", Text::plain( 'If you had to eat one country&#8217;s food?' ) );
	}

	public function testDecodesNamedAndBasicEntities(): void {
		$this->assertSame( 'Tom & Jerry say "hi" \'there\' …', Text::plain( 'Tom &amp; Jerry say &quot;hi&quot; &#039;there&#039; &hellip;' ) );
	}
}
