<?php
/**
 * [pulse_question]                          today's question
 * [pulse_question background="0"]           without its background text
 * [pulse_question answers="5"]              with its five top answers (0 = none)
 * [pulse_question recent="0" box="0"]       without recent answers or the answer box
 * [pulse_question_answer_box]              the answer box for today's question
 * [pulse_question_top_answers count="3"]    today's top answers (yesterday's until today has some)
 * [pulse_question_recent_answers count="5"] the newest answers to any question
 * [pulse_question_answers_button id="12"]   a question's answers button, as on the cards (for page builders)
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
		add_shortcode( 'pulse_question_answer_box', static fn (): string => Render::answerBox() );
		add_shortcode( 'pulse_question_top_answers', static fn ( $atts = array() ): string => Render::topAnswers( self::count( $atts, 'home_top_answers', 10 ) ) );
		add_shortcode( 'pulse_question_recent_answers', static fn ( $atts = array() ): string => Render::recentAnswers( self::count( $atts, 'home_recent_answers', 20 ) ) );
		add_shortcode(
			'pulse_question_answers_button',
			static function ( $atts = array() ): string {
				$id = (int) ( ( (array) $atts )['id'] ?? 0 );
				$id = $id > 0 ? $id : (int) get_the_ID();
				return Questions::POST_TYPE === get_post_type( $id ) ? Render::cardButton( $id ) : '';
			}
		);
	}

	/** @param array<string, string>|string $atts Attributes. */
	public static function today( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'background' => '1',
				'answers'    => '',
				'recent'     => '',
				'box'        => '',
			),
			(array) $atts,
			'pulse_question'
		);
		$num = static fn ( string $v ): int => '' === $v ? -1 : max( 0, (int) $v );
		return Render::today( '0' !== $atts['background'], $num( $atts['answers'] ), $num( $atts['recent'] ), $num( $atts['box'] ) > 0 ? 1 : $num( $atts['box'] ) );
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

	/**
	 * A count attribute, or the setting when it's missing.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	private static function count( array|string $atts, string $setting, int $max ): int {
		$atts = (array) $atts;
		return isset( $atts['count'] ) && '' !== $atts['count'] ? max( 0, min( $max, (int) $atts['count'] ) ) : (int) \PulseQuestion\Settings::get( $setting );
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
