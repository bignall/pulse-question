<?php
/**
 * Unit tests for Branding::withAppleTouchIcon (plain PHP, no WordPress needed).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Tests;

use PHPUnit\Framework\TestCase;
use PulseQuestion\Branding;

final class AppleTouchIconTest extends TestCase {

	public function testReplacesWordPressAppleTouchIcon(): void {
		$tags = array(
			'<link rel="icon" href="https://example.com/icon-32.png" sizes="32x32" />',
			'<link rel="icon" href="https://example.com/icon-192.png" sizes="192x192" />',
			'<link rel="apple-touch-icon" href="https://example.com/icon-180.png" />',
			'<meta name="msapplication-TileImage" content="https://example.com/icon-270.png" />',
		);
		$new  = '<link rel="apple-touch-icon" href="https://example.com/home.png" />';
		$out  = Branding::withAppleTouchIcon( $tags, $new );
		$this->assertSame( array( $tags[0], $tags[1], $tags[3], $new ), $out );
	}

	public function testAddsOneWhenNoneAndDropsPrecomposed(): void {
		$new = '<link rel="apple-touch-icon" href="x.png" />';
		$this->assertSame( array( $new ), Branding::withAppleTouchIcon( array(), $new ) );
		$this->assertSame( array( $new ), Branding::withAppleTouchIcon( array( "<link rel='apple-touch-icon-precomposed' href='y.png' />" ), $new ) );
	}
}
