<?php
/**
 * A query argument for page builders and custom code: pq_view.
 *
 *     new WP_Query( [ 'post_type' => 'pq_question', 'pq_view' => 'today' ] );   // today's question
 *     new WP_Query( [ 'post_type' => 'pq_question', 'pq_view' => 'past', 'posts_per_page' => 12 ] );
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

	public const VAR = 'pq_view';

	public static function init(): void {
		add_action( 'pre_get_posts', array( self::class, 'apply' ) );
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
