<?php
/**
 * [pulse_question]                          today's question
 * [pulse_question background="0"]           without its background text
 * [pulse_question answers="5"]              with its five top answers (0 = none)
 * [pulse_question_past]                     cards of past questions, with page links
 * [pulse_question_past count="6" paged="0"] the six latest, no page links
 * [pulse_question_past category="ethics"]   one category (slug or id)
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Front;

use PulseQuestion\Questions;

final class Shortcodes {

	public static function init(): void {
		add_shortcode( 'pulse_question', array( self::class, 'today' ) );
		add_shortcode( 'pulse_question_past', array( self::class, 'past' ) );
	}

	/** @param array<string, string>|string $atts Attributes. */
	public static function today( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'background' => '1',
				'answers'    => '',
			),
			(array) $atts,
			'pulse_question'
		);
		return Render::today( '0' !== $atts['background'], '' === $atts['answers'] ? -1 : max( 0, (int) $atts['answers'] ) );
	}

	/** @param array<string, string>|string $atts Attributes. */
	public static function past( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'count'    => '0',
				'category' => '',
				'paged'    => '1',
			),
			(array) $atts,
			'pulse_question_past'
		);
		return Render::past( (int) $atts['count'], self::categoryId( (string) $atts['category'] ), '0' !== $atts['paged'] );
	}

	public static function categoryId( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$term = get_term_by( 'slug', sanitize_title( $value ), Questions::TAXONOMY );
		return $term instanceof \WP_Term ? $term->term_id : -1;
	}
}
