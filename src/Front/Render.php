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

use PulseQuestion\Answers\Votes;
use PulseQuestion\App;
use PulseQuestion\Daily;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class Render {

	public const PAGE_VAR = 'pulse_question_page';

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
		$css = \PulseQuestion\Branding::inlineCss();
		if ( '' !== $css ) {
			wp_add_inline_style( 'pulse-question', $css );
		}
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

	/**
	 * Today's question, large, with the answer box, its top answers and recent answers.
	 *
	 * @param int $answers How many top answers to show under it; -1 for the setting.
	 * @param int $recent  How many recent answers to any question; -1 for the setting.
	 * @param int $box     Whether to show the answer box: 1, 0, or -1 for the setting.
	 */
	public static function today( bool $show_background = true, int $answers = -1, int $recent = -1, int $box = -1 ): string {
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
			$show_box = $box < 0 ? (bool) Settings::get( 'home_answer_form' ) : (bool) $box;
			if ( $show_box ) {
				$html .= self::answerBox( (int) $post->ID );
			} else {
				$n     = (int) get_comments_number( $post->ID );
				$html .= '<p class="pq-today__answers"><a class="pq-button pq-button--primary" href="' . esc_url( get_permalink( $post ) . '#pq-answers' ) . '">' . esc_html__( 'Answer', 'pulse-question' ) . '</a> <span>'
					/* translators: %s: number of answers. */
					. esc_html( $n ? sprintf( _n( '%s answer so far', '%s answers so far', $n, 'pulse-question' ), number_format_i18n( $n ) ) : __( 'Be the first to answer', 'pulse-question' ) ) . '</span></p>';
			}
			$shown = array();
			$html .= self::topAnswers( $answers < 0 ? (int) Settings::get( 'home_top_answers' ) : min( 10, $answers ), (int) $post->ID, $shown );
			$html .= self::recentAnswers( $recent < 0 ? (int) Settings::get( 'home_recent_answers' ) : min( 20, $recent ), $shown );
		}
		$html .= \PulseQuestion\Social\ShareButtons::render( (int) $post->ID );
		$html .= '</section>';
		return $html;
	}

	/** The id of today's question, or 0. */
	private static function todayId(): int {
		$today = Daily::current();
		return null === $today ? 0 : (int) $today['id'];
	}

	/**
	 * Answer today's question right here: the form (or log in and join buttons), with a thank-you
	 * after posting. Comes back to the page it's on.
	 */
	public static function answerBox( int $post_id = 0 ): string {
		$post_id = $post_id > 0 ? $post_id : self::todayId();
		if ( $post_id <= 0 || ! Settings::get( 'answers_open' ) || ! comments_open( $post_id ) ) {
			return '';
		}
		self::style();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$held   = isset( $_GET['pulse_question_held'] ) ? absint( $_GET['pulse_question_held'] ) : 0;
		$posted = isset( $_GET['pulse_question_posted'] ) ? absint( $_GET['pulse_question_posted'] ) : 0;
		// phpcs:enable
		$user  = get_current_user_id();
		$url   = (string) get_permalink( $post_id );
		$n     = (int) get_comments_number( $post_id );
		$here  = is_singular() ? (string) get_permalink( get_queried_object_id() ) : home_url( '/' );
		$html  = '<div class="pq-answer-box" id="pq-answer-box">';
		$html .= '<p class="pq-answer-box__count"><a href="' . esc_url( $url . '#pq-answers' ) . '">'
			/* translators: %s: number of answers. */
			. esc_html( $n ? sprintf( _n( '%s answer so far', '%s answers so far', $n, 'pulse-question' ), number_format_i18n( $n ) ) : __( 'Be the first to answer', 'pulse-question' ) ) . '</a></p>';
		$mine = $posted ? get_comment( $posted ) : null;
		if ( $mine instanceof \WP_Comment && (int) $mine->user_id === $user && $user > 0 && '1' === $mine->comment_approved ) {
			$html .= '<p class="pq-answers__note">' . esc_html__( 'Thanks, your answer is up!', 'pulse-question' ) . ' <a href="' . esc_url( $url . '#comment-' . $posted ) . '">' . esc_html__( 'See it with everyone else\'s', 'pulse-question' ) . ' &rarr;</a></p>';
		} else {
			$html .= AnswersView::form( $post_id, $user, $held, $here );
		}
		return $html . '</div>';
	}

	/**
	 * The most-liked answers so far, shortened, each linking to the full answer. Until today's
	 * question has answers, yesterday's top answers.
	 *
	 * @param list<int> $shown Filled with the answers shown, so recent answers can leave them out.
	 */
	public static function topAnswers( int $count, int $post_id = 0, array &$shown = array() ): string {
		$post_id = $post_id > 0 ? $post_id : self::todayId();
		if ( $count <= 0 || $post_id <= 0 ) {
			return '';
		}
		self::style();
		$answers = AnswersView::top( $post_id, $count );
		$title   = __( 'Top answers so far', 'pulse-question' );
		$from    = $post_id;
		$intro   = '';
		if ( ! $answers ) {
			$today = Daily::current();
			$from  = Questions::previous( $post_id, null !== $today && $today['id'] === $post_id ? $today['day'] : '' );
			if ( $from <= 0 ) {
				return '';
			}
			$answers = AnswersView::top( $from, $count );
			$title   = __( 'Yesterday\'s top answers', 'pulse-question' );
			$intro   = '<p class="pq-top-answers__question"><a href="' . esc_url( (string) get_permalink( $from ) ) . '">' . esc_html( get_the_title( $from ) ) . '</a></p>';
		}
		if ( ! $answers ) {
			return '';
		}
		$url  = (string) get_permalink( $from );
		$html = '<div class="pq-top-answers"><h3 class="pq-top-answers__title">' . esc_html( $title ) . '</h3>' . $intro . '<ol class="pq-top-answers__list">';
		foreach ( $answers as $answer ) {
			$shown[] = (int) $answer->comment_ID;
			$html   .= self::answerItem( $answer );
		}
		$html .= '</ol>';
		$total = (int) get_comments_number( $from );
		if ( $total > count( $answers ) ) {
			$html .= '<p class="pq-top-answers__more"><a href="' . esc_url( $url . '#pq-answers' ) . '">'
				/* translators: %s: number of answers. */
				. esc_html( sprintf( _n( 'Read %s answer', 'Read all %s answers', $total, 'pulse-question' ), number_format_i18n( $total ) ) ) . ' &rarr;</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * The newest published answers to any question, with the question each answers.
	 *
	 * @param list<int> $exclude Answers already shown.
	 */
	public static function recentAnswers( int $count, array $exclude = array() ): string {
		if ( $count <= 0 ) {
			return '';
		}
		self::style();
		$answers = get_comments(
			array(
				'post_type'      => Questions::POST_TYPE,
				'status'         => 'approve',
				'parent'         => 0,
				'type'           => 'comment',
				'number'         => $count,
				'comment__not_in' => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams
				'orderby'        => 'comment_date_gmt',
				'order'          => 'DESC',
			)
		);
		if ( ! $answers ) {
			return '';
		}
		$html = '<div class="pq-top-answers pq-recent-answers"><h3 class="pq-top-answers__title">' . esc_html__( 'Recent answers', 'pulse-question' ) . '</h3><ol class="pq-top-answers__list">';
		foreach ( $answers as $answer ) {
			$html .= self::answerItem( $answer, true );
		}
		return $html . '</ol></div>';
	}

	/** One shortened answer, linking to it in full. */
	private static function answerItem( \WP_Comment $answer, bool $with_question = false ): string {
		$id       = (int) $answer->comment_ID;
		$question = (int) $answer->comment_post_ID;
		$likes    = Votes::count( 'answer', $id );
		$name     = '' !== $answer->comment_author ? $answer->comment_author : __( 'A member', 'pulse-question' );
		$meta     = esc_html( $name );
		if ( $likes > 0 ) {
			/* translators: %s: number of likes. */
			$meta .= ' · ' . esc_html( sprintf( _n( '%s like', '%s likes', $likes, 'pulse-question' ), number_format_i18n( $likes ) ) );
		}
		if ( $with_question ) {
			/* translators: %s: the question. */
			$meta .= ' · ' . esc_html( sprintf( __( 'on “%s”', 'pulse-question' ), wp_trim_words( get_the_title( $question ), 10, '…' ) ) );
		}
		return '<li class="pq-top-answer"><a class="pq-top-answer__link" href="' . esc_url( get_permalink( $question ) . '#comment-' . $id ) . '">'
			. '<span class="pq-top-answer__text">' . esc_html( wp_trim_words( wp_strip_all_tags( $answer->comment_content ), 40, '…' ) ) . '</span>'
			. '<span class="pq-top-answer__meta">' . $meta . '</span></a></li>';
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
			$html .= '<div class="pq-card__foot">' . self::categories( $post->ID ) . self::cardButton( $post->ID ) . '</div>';
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

	/** The answers button on a past question's card: "12 answers", or "Answer" when there are none yet. */
	public static function cardButton( int $id ): string {
		$n = (int) get_comments_number( $id );
		if ( ! $n && ! ( Settings::get( 'answers_open' ) && comments_open( $id ) ) ) {
			return '';
		}
		/* translators: %s: number of answers. */
		$label = $n ? sprintf( _n( '%s answer', '%s answers', $n, 'pulse-question' ), number_format_i18n( $n ) ) : __( 'Answer', 'pulse-question' );
		return '<a class="pq-card__answers" href="' . esc_url( get_permalink( $id ) . '#pq-answers' ) . '">' . self::bubble() . '<span>' . esc_html( $label ) . '</span></a>';
	}

	private static function bubble(): string {
		return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>';
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
		$extra .= \PulseQuestion\Social\ShareButtons::render( (int) get_the_ID() );
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
