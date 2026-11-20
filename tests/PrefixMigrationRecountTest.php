<?php
/**
 * Unit tests for PrefixMigration::maybeRecount(), with the few WordPress functions it calls
 * stubbed in its namespace.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion {

	use PulseQuestion\Tests\PrefixMigrationRecountTest as T;

	function get_option( string $name, $fallback = false ) {
		return T::$options[ $name ] ?? $fallback;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		T::$options[ $name ] = $value;
		return true;
	}

	function taxonomy_exists( string $taxonomy ): bool {
		return T::$registered;
	}

	function get_terms( array $args ) {
		T::$termArgs = $args;
		return T::$terms;
	}

	function wp_update_term_count_now( array $ids, string $taxonomy ): bool {
		T::$recounts[] = array( $ids, $taxonomy );
		return true;
	}
}

namespace PulseQuestion\Tests {

	use PHPUnit\Framework\TestCase;
	use PulseQuestion\PrefixMigration;

	final class PrefixMigrationRecountTest extends TestCase {

		public static array $options   = array();
		public static bool $registered = true;
		public static array $terms     = array();
		public static array $termArgs  = array();
		public static array $recounts  = array();

		protected function setUp(): void {
			self::$options    = array();
			self::$registered = true;
			self::$terms      = array();
			self::$termArgs   = array();
			self::$recounts   = array();
		}

		public function testRecountsEveryCategoryOnceAfterMigrating(): void {
			self::$terms = array( '3', '7', '12' );

			PrefixMigration::maybeRecount();

			$this->assertSame( 'pulse_question_category', self::$termArgs['taxonomy'] );
			$this->assertFalse( self::$termArgs['hide_empty'], 'Zero counts are the problem, so empty ones must be included.' );
			$this->assertSame( 'tt_ids', self::$termArgs['fields'] );
			$this->assertSame( array( array( array( 3, 7, 12 ), 'pulse_question_category' ) ), self::$recounts );
			$this->assertArrayHasKey( PrefixMigration::RECOUNTED, self::$options );

			// Later requests leave the counts to WordPress.
			PrefixMigration::maybeRecount();
			$this->assertCount( 1, self::$recounts );
		}

		public function testSitesAlreadyMigratedAreRecountedToo(): void {
			// Migrated by 0.6.0 or 0.6.1: no pq_ data left, no recount flag yet.
			self::$terms = array( 5 );
			PrefixMigration::maybeRecount();
			$this->assertSame( array( array( array( 5 ), 'pulse_question_category' ) ), self::$recounts );
		}

		public function testWaitsForTheTaxonomyToBeRegistered(): void {
			self::$registered = false;
			self::$terms      = array( 5 );

			PrefixMigration::maybeRecount();

			$this->assertSame( array(), self::$recounts );
			$this->assertArrayNotHasKey( PrefixMigration::RECOUNTED, self::$options );
		}

		public function testNoCategoriesStillMarksItDone(): void {
			PrefixMigration::maybeRecount();
			$this->assertSame( array(), self::$recounts );
			$this->assertArrayHasKey( PrefixMigration::RECOUNTED, self::$options );
		}
	}
}
