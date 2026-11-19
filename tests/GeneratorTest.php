<?php
/**
 * Unit tests for the parts of Generator that don't need WordPress.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Tests;

use PHPUnit\Framework\TestCase;
use PulseQuestion\Claude\Generator;

final class GeneratorTest extends TestCase {

	public function testRepeatsMatchWhateverThePunctuationAndCase(): void {
		$this->assertSame( Generator::normalize( 'What makes a place feel like home?' ), Generator::normalize( 'what makes a place feel like HOME' ) );
		$this->assertSame( Generator::normalize( "What's your earliest memory?" ), Generator::normalize( 'Whats your earliest memory' ) );
		$this->assertNotSame( Generator::normalize( 'What makes a place feel like home?' ), Generator::normalize( 'What makes a person feel like home?' ) );
		$this->assertSame( 'quétalvidaenespaña', Generator::normalize( '¿Qué tal vida en España?' ) );
		$this->assertSame( '', Generator::normalize( '  ?!  ' ) );
	}

	public function testSchemaIsStrict(): void {
		$schema = Generator::schema();
		$item   = $schema['properties']['questions']['items'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertFalse( $item['additionalProperties'] );
		$this->assertSame( array( 'questions' ), $schema['required'] );
		$this->assertSame( array( 'question', 'background', 'category' ), $item['required'] );
		$this->assertSame( array_keys( $item['properties'] ), $item['required'] );
	}

	public function testDefaultGuidanceKeepsPoliticsRare(): void {
		$this->assertStringContainsString( 'politics', Generator::DEFAULT_GUIDANCE );
		$this->assertStringContainsString( 'rare occasions', Generator::DEFAULT_GUIDANCE );
		$this->assertStringContainsString( 'Big variety', Generator::DEFAULT_GUIDANCE );
	}
}
