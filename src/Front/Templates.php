<?php
/**
 * Templates for block themes (WordPress 6.7+): a question's own page, the /questions/ page
 * (today's question and the past ones) and category pages. The theme's own templates, or ones
 * changed in the Site Editor, take over. Turn them off with the pulse_question_block_templates filter.
 *
 * Without these, block themes show questions with their blog post template: an empty
 * "Written by" line and a "More posts" list of blog posts.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Front;

use PulseQuestion\Questions;

final class Templates {

	public const NAMESPACE = 'pulse-question';

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 20 );
	}

	public static function register(): void {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}
		/**
		 * Whether to supply block templates for questions.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'pulse_question_block_templates', true ) ) {
			return;
		}
		register_block_template(
			self::NAMESPACE . '//single-' . Questions::POST_TYPE,
			array(
				'title'       => __( 'Question', 'pulse-question' ),
				'description' => __( 'A question\'s own page: the question, its background and when it was asked.', 'pulse-question' ),
				'content'     => self::wrap( '<!-- wp:post-title {"level":1} /-->' . "\n\n" . '<!-- wp:post-content {"layout":{"type":"constrained"}} /-->' ),
				'post_types'  => array( Questions::POST_TYPE ),
			)
		);
		register_block_template(
			self::NAMESPACE . '//archive-' . Questions::POST_TYPE,
			array(
				'title'       => __( 'Questions', 'pulse-question' ),
				'description' => __( 'The /questions/ page: today\'s question, then the past ones.', 'pulse-question' ),
				'content'     => self::wrap( '<!-- wp:pulse-question/today /-->' . "\n\n" . self::heading( __( 'Past questions', 'pulse-question' ) ) . "\n\n" . '<!-- wp:pulse-question/past /-->' ),
			)
		);
		register_block_template(
			self::NAMESPACE . '//taxonomy-' . Questions::TAXONOMY,
			array(
				'title'       => __( 'Question category', 'pulse-question' ),
				'description' => __( 'The past questions in one category.', 'pulse-question' ),
				'content'     => self::wrap( '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->' . "\n\n" . '<!-- wp:term-description /-->' . "\n\n" . '<!-- wp:pulse-question/past /-->' ),
			)
		);
	}

	private static function heading( string $text ): string {
		return '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">' . esc_html( $text ) . '</h2>' . "\n" . '<!-- /wp:heading -->';
	}

	private static function wrap( string $inner ): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n\n"
			. '<!-- wp:group {"tagName":"main","className":"pq-template","layout":{"type":"constrained"}} -->' . "\n"
			. '<main class="wp-block-group pq-template">' . $inner . '</main>' . "\n"
			. '<!-- /wp:group -->' . "\n\n"
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}
}
