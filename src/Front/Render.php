<?php
/**
 * HTML for today's question, the cards of past questions, and the extra line on a question's page.
 *
 * Markup uses pq- classes and CSS custom properties (--pq-accent and friends), so themes can
 * restyle it without overriding the plugin's stylesheet.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Front;

use PulseQuestion\App;
use PulseQuestion\Daily;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class Render {

	public const PAGE_VAR = 'pq_page';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'registerStyle' ) );
		add_filter( 'the_content', array( self::class, 'singleContent' ) );
		add_filter( 'query_vars', array( self::class, 'queryVars' ) );
		add_action( 'pre_get_posts', array( self::class, 'archiveOrder' ) );
	}

	/** The theme's question and category archives: last asked first, like the cards. */
	public static function archiveOrder( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! ( $query->is_post_type_archive( Questions::POST_TYPE ) || $query->is_tax( Questions::TAXONOMY ) ) ) {
			return;
		}
		$query->set( 'meta_key', Questions::META_LAST );
		$query->set(
			'orderby',
			array(
				'meta_value' => 'DESC',
				'ID'         => 'DESC',
			)
		);
	}

	public static function registerStyle(): void {
		wp_register_style( 'pulse-question', App::url( 'assets/front.css' ), array(), App::version() );
	}

	/**
	 * @param list<string> $vars Public query vars.
	 * @return list<string>
	 */
	public static function queryVars( array $vars ): array {
		$vars[] = self::PAGE_VAR;
		return $vars;
	}

	private static function style(): void {
		if ( ! Settings::get( 'load_styles' ) ) {
			return;
		}
		if ( ! wp_style_is( 'pulse-question', 'registered' ) ) {
			self::registerStyle();
		}
		wp_enqueue_style( 'pulse-question' );
	}

	/** "Thursday, November 19, 2026" for a Y-m-d day, in the site's format. */
	public static function dayLabel( string $day, bool $long = true ): string {
		if ( '' === $day ) {
			return '';
		}
		$moment = new \DateTimeImmutable( $day . ' 12:00', wp_timezone() );
		return wp_date( $long ? 'l, ' . get_option( 'date_format' ) : get_option( 'date_format' ), $moment->getTimestamp() );
	}

	/** Today's question, large. */
	public static function today( bool $show_background = true ): string {
		$today = Daily::current();
		self::style();
		if ( null === $today ) {
			return '<section class="pq-today pq-today--empty"><p>' . esc_html__( 'The first question is coming soon.', 'pulse-question' ) . '</p></section>';
		}
		$post  = get_post( $today['id'] );
		$label = (string) Settings::get( 'label' );
		$html  = '<section class="pq-today" aria-labelledby="pq-today-' . (int) $post->ID . '">';
		$html .= '<p class="pq-today__label">' . ( '' !== $label ? '<span>' . esc_html( $label ) . '</span> ' : '' ) . '<time datetime="' . esc_attr( $today['day'] ) . '">' . esc_html( self::dayLabel( $today['day'] ) ) . '</time></p>';
		$html .= '<h2 class="pq-today__question" id="pq-today-' . (int) $post->ID . '"><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h2>';
		if ( $show_background && '' !== trim( $post->post_content ) ) {
			$html .= '<div class="pq-today__background">' . wp_kses_post( wpautop( $post->post_content ) ) . '</div>';
		}
		$html .= self::categories( $post->ID );
		if ( Settings::get( 'answers_open' ) ) {
			$n     = (int) get_comments_number( $post->ID );
			$html .= '<p class="pq-today__answers"><a class="pq-button pq-button--primary" href="' . esc_url( get_permalink( $post ) . '#pq-answers' ) . '">' . esc_html__( 'Answer', 'pulse-question' ) . '</a> <span>'
				/* translators: %s: number of answers. */
				. esc_html( $n ? sprintf( _n( '%s answer so far', '%s answers so far', $n, 'pulse-question' ), number_format_i18n( $n ) ) : __( 'Be the first to answer', 'pulse-question' ) ) . '</span></p>';
		}
		$html .= '</section>';
		return $html;
	}

	/**
	 * Cards of questions asked before today, newest first, with page links.
	 *
	 * @param int $category Only this category (term id), or 0.
	 */
	public static function past( int $per_page = 0, int $category = 0, bool $paged = true ): string {
		self::style();
		$per_page = $per_page > 0 ? $per_page : (int) Settings::get( 'per_page' );
		if ( 0 === $category && is_tax( Questions::TAXONOMY ) ) {
			$category = (int) get_queried_object_id(); // On a category page, that category.
		}
		$page     = $paged ? max( 1, (int) get_query_var( self::PAGE_VAR ) ) : 1;
		$today    = Daily::stored();
		$args     = array(
			'post_type'      => Questions::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_key'       => Questions::META_LAST, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => array(
				'meta_value' => 'DESC',
				'ID'         => 'DESC',
			),
			'post__not_in'   => $today ? array( $today['id'] ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams
		);
		if ( $category > 0 ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => Questions::TAXONOMY,
					'terms'    => $category,
				),
			);
		}
		$query = new \WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return '<p class="pq-past pq-past--empty">' . esc_html__( 'Past questions will show here.', 'pulse-question' ) . '</p>';
		}

		$html = '<div class="pq-past"><ul class="pq-cards">';
		foreach ( $query->posts as $post ) {
			$day   = (string) get_post_meta( $post->ID, Questions::META_LAST, true );
			$html .= '<li class="pq-card">';
			$html .= '<time class="pq-card__day" datetime="' . esc_attr( $day ) . '">' . esc_html( self::dayLabel( $day, false ) ) . '</time>';
			$html .= '<h3 class="pq-card__question"><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3>';
			$html .= self::categories( $post->ID );
			$n     = (int) get_comments_number( $post->ID );
			if ( $n ) {
				/* translators: %s: number of answers. */
				$html .= '<span class="pq-card__answers">' . esc_html( sprintf( _n( '%s answer', '%s answers', $n, 'pulse-question' ), number_format_i18n( $n ) ) ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';

		if ( $paged && $query->max_num_pages > 1 ) {
			$links = paginate_links(
				array(
					'base'      => add_query_arg( self::PAGE_VAR, '%#%' ),
					'format'    => '',
					'current'   => $page,
					'total'     => (int) $query->max_num_pages,
					'prev_text' => __( '&larr; Newer', 'pulse-question' ),
					'next_text' => __( 'Older &rarr;', 'pulse-question' ),
					'type'      => 'list',
				)
			);
			$html .= '<nav class="pq-pages" aria-label="' . esc_attr__( 'Past questions pages', 'pulse-question' ) . '">' . $links . '</nav>';
		}
		$html .= '</div>';
		return $html;
	}

	private static function categories( int $id ): string {
		$terms = get_the_terms( $id, Questions::TAXONOMY );
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}
		$out = '<ul class="pq-cats">';
		foreach ( $terms as $term ) {
			$out .= '<li><a href="' . esc_url( (string) get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></li>';
		}
		return $out . '</ul>';
	}

	/** On a question's own page: when it was asked, under the background text. */
	public static function singleContent( string $content ): string {
		if ( ! is_singular( Questions::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		self::style();
		$days  = Questions::askedDays( (int) get_the_ID() );
		$today = Daily::stored();
		$extra = '<p class="pq-asked">';
		if ( $today && $today['id'] === (int) get_the_ID() ) {
			$extra .= '<strong>' . esc_html( (string) Settings::get( 'label' ) ) . '</strong> · ';
		}
		if ( $days ) {
			$extra .= esc_html(
				sprintf(
					/* translators: %s: list of dates. */
					__( 'Asked %s', 'pulse-question' ),
					implode( ', ', array_map( static fn ( string $d ): string => self::dayLabel( $d, false ), $days ) )
				)
			);
		}
		$extra .= '</p>' . self::categories( (int) get_the_ID() );
		if ( Settings::get( 'answers_open' ) || get_comments_number( (int) get_the_ID() ) ) {
			$extra .= AnswersView::render( (int) get_the_ID() );
		}
		$archive = get_post_type_archive_link( Questions::POST_TYPE );
		if ( $archive ) {
			$extra .= '<p class="pq-more"><a href="' . esc_url( $archive ) . '">' . esc_html__( 'More questions', 'pulse-question' ) . ' &rarr;</a></p>';
		}
		return $content . $extra;
	}
}
