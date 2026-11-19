<?php
/**
 * A query argument for page builders and custom code: pulse_question_view.
 *
 *     new WP_Query( [ 'post_type' => 'pulse_question', 'pulse_question_view' => 'today' ] );   // today's question
 *     new WP_Query( [ 'post_type' => 'pulse_question', 'pulse_question_view' => 'past', 'posts_per_page' => 12 ] );
 *
 * Before 0.6.0 these were post type pq_question, taxonomy pq_category and pq_view. Queries
 * that still use those names (an Etch loop set up earlier, say) keep working.
 *
 * "today" finds the question of the day (none before the first one). "past" finds asked questions
 * except today's, last asked first. Anything that passes WP_Query arguments through, such as an
 * Etch WP Query loop, can use it.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Front;

use PulseQuestion\Daily;
use PulseQuestion\Questions;

final class QueryViews {

	public const VAR = 'pulse_question_view';

	public static function init(): void {
		add_action( 'pre_get_posts', array( self::class, 'oldNames' ), 0 );
		add_action( 'pre_get_posts', array( self::class, 'apply' ) );
	}

	/** Queries using the names from before 0.6.0 get today's names. */
	public static function oldNames( \WP_Query $query ): void {
		$type = $query->get( 'post_type' );
		if ( 'pq_question' === $type ) {
			$query->set( 'post_type', Questions::POST_TYPE );
		} elseif ( is_array( $type ) && in_array( 'pq_question', $type, true ) ) {
			$query->set( 'post_type', array_values( array_unique( array_map( static fn ( $t ) => 'pq_question' === $t ? Questions::POST_TYPE : $t, $type ) ) ) );
		}
		$old_view = $query->get( 'pq_view' );
		if ( is_string( $old_view ) && '' !== $old_view && '' === (string) $query->get( self::VAR ) ) {
			$query->set( self::VAR, $old_view );
		}
		$old_cat = $query->get( 'pq_category' );
		if ( '' !== $old_cat && null !== $old_cat && '' === (string) $query->get( Questions::TAXONOMY ) ) {
			$query->set( Questions::TAXONOMY, $old_cat );
		}
		$tax = $query->get( 'tax_query' );
		if ( is_array( $tax ) ) {
			array_walk_recursive(
				$tax,
				static function ( &$value, $key ): void {
					if ( 'taxonomy' === $key && 'pq_category' === $value ) {
						$value = Questions::TAXONOMY;
					}
				}
			);
			$query->set( 'tax_query', $tax );
		}
	}

	public static function apply( \WP_Query $query ): void {
		$view = $query->get( self::VAR );
		if ( ! is_string( $view ) || '' === $view ) {
			return;
		}
		$query->set( 'post_type', Questions::POST_TYPE );
		$query->set( 'post_status', 'publish' );
		$query->set( 'ignore_sticky_posts', true );

		if ( 'today' === $view ) {
			$today = Daily::current();
			// No question yet: match nothing.
			$query->set( 'post__in', array( null !== $today ? $today['id'] : 0 ) );
			$query->set( 'posts_per_page', 1 );
			return;
		}
		if ( 'past' === $view ) {
			$today = Daily::stored();
			if ( null !== $today ) {
				$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), array( $today['id'] ) ) );
			}
			$query->set(
				'orderby',
				array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				)
			);
		}
	}
}
