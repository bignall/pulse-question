<?php
/**
 * The answers section under a question: a vote for the question, the answer form (or log in /
 * join links), and the answers with their replies, sorted by votes or newest first.
 *
 * Answers post to wp-comments-post.php like any comment, so Akismet and other comment plugins
 * work. Replies are one level deep: a reply to a reply joins the same answer.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Front;

use PulseQuestion\Answers\Answers;
use PulseQuestion\Answers\Votes;
use PulseQuestion\App;
use PulseQuestion\Members\Members;
use PulseQuestion\Settings;

final class AnswersView {

	private const PER_PAGE = 30;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register' ) );
		add_filter( 'comment_post_redirect', array( self::class, 'afterPost' ), 10, 2 );
	}

	public static function register(): void {
		wp_register_script( 'pulse-question-answers', App::url( 'assets/answers.js' ), array(), App::version(), true );
	}

	/** After posting, back to the answers (WordPress adds #comment-N for published ones). */
	public static function afterPost( string $location, \WP_Comment $comment ): string {
		if ( ! Answers::isAnswer( $comment ) || '1' === $comment->comment_approved ) {
			return $location;
		}
		return add_query_arg( 'pq_held', $comment->comment_ID, get_permalink( (int) $comment->comment_post_ID ) ) . '#pq-answers';
	}

	public static function render( int $post_id ): string {
		$user  = get_current_user_id();
		$count = (int) get_comments_number( $post_id );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display options only.
		$sort = isset( $_GET['pq_sort'] ) && 'new' === $_GET['pq_sort'] ? 'new' : 'top';
		$page = isset( $_GET['pq_ap'] ) ? max( 1, absint( $_GET['pq_ap'] ) ) : 1;
		$held = isset( $_GET['pq_held'] ) ? absint( $_GET['pq_held'] ) : 0;
		$sent = isset( $_GET['pq_reported'] );
		// phpcs:enable

		if ( ! wp_script_is( 'pulse-question-answers', 'registered' ) ) {
			self::register();
		}
		{
			wp_enqueue_script( 'pulse-question-answers' );
			wp_localize_script(
				'pulse-question-answers',
				'pulseQuestion',
				array(
					'api'          => esc_url_raw( rest_url( 'pulse-question/v1/' ) ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'reportAsk'    => __( 'Report this answer to the moderators?', 'pulse-question' ),
					'reportThanks' => __( 'Thanks, the moderators will take a look.', 'pulse-question' ),
					'replyingTo'   => __( 'Replying to %s', 'pulse-question' ),
				)
			);
		}

		$html  = '<section class="pq-answers" id="pq-answers" aria-labelledby="pq-answers-title">';
		$html .= '<div class="pq-answers__head">';
		$html .= '<h2 class="pq-answers__title" id="pq-answers-title">' . esc_html( sprintf( _n( '%s answer', '%s answers', $count, 'pulse-question' ), number_format_i18n( $count ) ) ) . '</h2>';
		$html .= self::voteButton( 'question', $post_id, $user, __( 'Good question', 'pulse-question' ) );
		if ( $count > 1 ) {
			$base  = get_permalink( $post_id );
			$html .= '<nav class="pq-answers__sort" aria-label="' . esc_attr__( 'Sort answers', 'pulse-question' ) . '">'
				. self::sortLink( (string) $base, 'top', __( 'Top', 'pulse-question' ), $sort )
				. self::sortLink( (string) $base, 'new', __( 'Newest', 'pulse-question' ), $sort ) . '</nav>';
		}
		$html .= '</div>';

		if ( $sent ) {
			$html .= '<p class="pq-answers__note">' . esc_html__( 'Thanks, the moderators will take a look.', 'pulse-question' ) . '</p>';
		}
		$html .= self::form( $post_id, $user, $held );
		$html .= self::list( $post_id, $user, $sort, $page, $count );
		$html .= '</section>';
		return $html;
	}

	private static function sortLink( string $base, string $value, string $label, string $current ): string {
		$url = add_query_arg( 'pq_sort', $value, $base ) . '#pq-answers';
		return '<a href="' . esc_url( $url ) . '"' . ( $value === $current ? ' aria-current="true" class="is-current"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}

	private static function form( int $post_id, int $user, int $held ): string {
		if ( ! comments_open( $post_id ) ) {
			return '';
		}
		$back = get_permalink( $post_id ) . '#pq-answers';
		if ( $user <= 0 ) {
			$join = get_option( 'users_can_register' )
				? ' <a class="pq-button" href="' . esc_url( add_query_arg( 'redirect_to', rawurlencode( (string) $back ), wp_registration_url() ) ) . '">' . esc_html__( 'Join', 'pulse-question' ) . '</a>'
				: '';
			return '<div class="pq-answers__join"><p>' . esc_html__( 'Members can answer, vote and reply.', 'pulse-question' ) . '</p>'
				. '<p><a class="pq-button pq-button--primary" href="' . esc_url( wp_login_url( (string) $back ) ) . '">' . esc_html__( 'Log in to answer', 'pulse-question' ) . '</a>' . $join . '</p></div>';
		}

		$out = '';
		if ( $held ) {
			$comment = get_comment( $held );
			if ( $comment instanceof \WP_Comment && (int) $comment->user_id === $user && '0' === $comment->comment_approved ) {
				$out .= '<p class="pq-answers__note">' . esc_html__( 'Thanks! Your answer is waiting for a quick review before it appears.', 'pulse-question' ) . '</p>';
			}
		}
		$max  = (int) Settings::get( 'answer_max_length' );
		$out .= '<form class="pq-answer-form" method="post" action="' . esc_url( site_url( '/wp-comments-post.php' ) ) . '">';
		$out .= '<label class="pq-answer-form__label" for="pq-answer-text">' . esc_html__( 'Your answer', 'pulse-question' ) . '</label>';
		$out .= '<p class="pq-answer-form__replying" hidden><span></span> <button type="button" class="pq-link" data-pq-cancel-reply>' . esc_html__( 'Cancel', 'pulse-question' ) . '</button></p>';
		$out .= '<textarea id="pq-answer-text" name="comment" rows="4" required' . ( $max > 0 ? ' maxlength="' . esc_attr( (string) $max ) . '"' : '' ) . '></textarea>';
		$out .= '<input type="hidden" name="comment_post_ID" value="' . esc_attr( (string) $post_id ) . '" />';
		$out .= '<input type="hidden" name="comment_parent" value="0" />';
		if ( ! Members::wasAsked( $user ) ) {
			$out .= Members::fields();
		}
		ob_start();
		/** Comment plugins (such as Akismet) add their fields here. */
		do_action( 'comment_form', $post_id );
		$out .= (string) ob_get_clean();
		$out .= '<p class="pq-answer-form__actions"><button type="submit" class="pq-button pq-button--primary">' . esc_html__( 'Post answer', 'pulse-question' ) . '</button> ';
		$out .= '<span class="pq-answer-form__hint">' . esc_html__( 'Answers are checked before they appear. Be kind; keep it clean.', 'pulse-question' ) . '</span></p>';
		$out .= '</form>';
		return $out;
	}

	private static function list( int $post_id, int $user, string $sort, int $page, int $count ): string {
		$args = array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'parent'  => 0,
			'type'    => 'comment',
			'number'  => self::PER_PAGE,
			'offset'  => ( $page - 1 ) * self::PER_PAGE,
		);
		if ( 'top' === $sort ) {
			$args['meta_key'] = Votes::META; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['orderby']  = array(
				'meta_value_num'   => 'DESC',
				'comment_date_gmt' => 'ASC',
			);
		} else {
			$args['orderby'] = 'comment_date_gmt';
			$args['order']   = 'DESC';
		}
		$answers = get_comments( $args );

		// The member's own answers waiting for review, shown only to them.
		$mine = $user > 0 && 1 === $page ? get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'hold',
				'user_id' => $user,
				'type'    => 'comment',
			)
		) : array();

		if ( ! $answers && ! $mine ) {
			return comments_open( $post_id ) ? '<p class="pq-answers__empty">' . esc_html__( 'No answers yet. Be the first.', 'pulse-question' ) . '</p>' : '';
		}

		$ids = array_map( static fn ( \WP_Comment $c ): int => (int) $c->comment_ID, $answers );
		$all = get_comments(
			array(
				'post_id'    => $post_id,
				'status'     => 'approve',
				'type'       => 'comment',
				'parent__in' => $ids ? $ids : array( 0 ),
				'orderby'    => 'comment_date_gmt',
				'order'      => 'ASC',
			)
		);
		$replies = array();
		foreach ( $all as $reply ) {
			$replies[ (int) $reply->comment_parent ][] = $reply;
		}
		$voted = Votes::votedAmong( 'answer', array_merge( $ids, array_map( static fn ( \WP_Comment $c ): int => (int) $c->comment_ID, $all ) ), $user );

		$html = '<ol class="pq-answers__list">';
		foreach ( $mine as $own ) {
			$html .= self::answer( $own, $user, $voted, array(), true );
		}
		foreach ( $answers as $answer ) {
			$html .= self::answer( $answer, $user, $voted, $replies[ (int) $answer->comment_ID ] ?? array(), false );
		}
		$html .= '</ol>';

		$pages = (int) ceil( self::topLevelCount( $post_id ) / self::PER_PAGE );
		if ( $pages > 1 ) {
			$links = paginate_links(
				array(
					'base'      => add_query_arg( array( 'pq_ap' => '%#%', 'pq_sort' => $sort ), (string) get_permalink( $post_id ) ) . '#pq-answers',
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'type'      => 'list',
					'prev_text' => __( '&larr; Previous', 'pulse-question' ),
					'next_text' => __( 'More answers &rarr;', 'pulse-question' ),
				)
			);
			$html .= '<nav class="pq-pages" aria-label="' . esc_attr__( 'More answers', 'pulse-question' ) . '">' . $links . '</nav>';
		}
		return $html;
	}

	private static function topLevelCount( int $post_id ): int {
		return (int) get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'approve',
				'parent'  => 0,
				'type'    => 'comment',
				'count'   => true,
			)
		);
	}

	/**
	 * One answer (or reply).
	 *
	 * @param array<int, true>  $voted   Answers the member voted for.
	 * @param list<\WP_Comment> $replies Its replies.
	 */
	private static function answer( \WP_Comment $c, int $user, array $voted, array $replies, bool $waiting, bool $is_reply = false ): string {
		$id    = (int) $c->comment_ID;
		$name  = '' !== $c->comment_author ? $c->comment_author : __( 'A member', 'pulse-question' );
		$date  = mysql2date( (string) get_option( 'date_format' ), $c->comment_date );
		$text  = wpautop( esc_html( $c->comment_content ) );
		$html  = '<li class="pq-answer' . ( $is_reply ? ' pq-answer--reply' : '' ) . ( $waiting ? ' pq-answer--waiting' : '' ) . '" id="comment-' . $id . '">';
		$html .= '<div class="pq-answer__head"><span class="pq-answer__name">' . esc_html( $name ) . '</span> <time class="pq-answer__date" datetime="' . esc_attr( mysql2date( 'c', $c->comment_date_gmt ) ) . '">' . esc_html( $date ) . '</time>';
		if ( $waiting ) {
			$html .= ' <span class="pq-answer__badge">' . esc_html__( 'Waiting for review: only you can see this', 'pulse-question' ) . '</span>';
		}
		$html .= '</div>';
		$html .= '<div class="pq-answer__text">' . $text . '</div>';
		if ( ! $waiting ) {
			$html .= '<div class="pq-answer__actions">';
			$html .= (int) $c->user_id === $user && $user > 0
				? '<span class="pq-vote pq-vote--own">' . self::heart() . '<span class="pq-vote__count">' . esc_html( number_format_i18n( Votes::count( 'answer', $id ) ) ) . '</span></span>'
				: self::voteButton( 'answer', $id, $user, '', isset( $voted[ $id ] ) );
			if ( $user > 0 && comments_open( (int) $c->comment_post_ID ) ) {
				$parent = $is_reply ? (int) $c->comment_parent : $id;
				$html  .= '<button type="button" class="pq-link" data-pq-reply="' . esc_attr( (string) $parent ) . '" data-pq-name="' . esc_attr( $name ) . '">' . esc_html__( 'Reply', 'pulse-question' ) . '</button>';
			}
			if ( $user > 0 && (int) $c->user_id !== $user ) {
				$html .= '<form class="pq-report" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. wp_nonce_field( 'pq_report', '_wpnonce', true, false )
					. '<input type="hidden" name="action" value="pq_report" /><input type="hidden" name="id" value="' . $id . '" />'
					. '<button type="submit" class="pq-link pq-link--quiet">' . esc_html__( 'Report', 'pulse-question' ) . '</button></form>';
			}
			$html .= '</div>';
		}
		if ( $replies ) {
			$html .= '<ol class="pq-replies">';
			foreach ( $replies as $reply ) {
				$html .= self::answer( $reply, $user, $voted, array(), false, true );
			}
			$html .= '</ol>';
		}
		return $html . '</li>';
	}

	private static function heart(): string {
		return '<svg class="pq-vote__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 21s-7.5-4.6-9.6-9.4C.9 8 3.1 4 6.9 4c2.1 0 3.6 1.1 5.1 3 1.5-1.9 3-3 5.1-3 3.8 0 6 4 4.5 7.6C19.5 16.4 12 21 12 21z"/></svg>';
	}

	/** A vote button (a form, so it works without JavaScript). Logged out: a link to log in. */
	private static function voteButton( string $type, int $id, int $user, string $label, ?bool $voted = null ): string {
		$count = Votes::count( $type, $id );
		$text  = '' !== $label ? '<span class="pq-vote__label">' . esc_html( $label ) . '</span>' : '';
		$num   = '<span class="pq-vote__count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
		$aria  = 'question' === $type ? __( 'Vote for this question', 'pulse-question' ) : __( 'Vote for this answer', 'pulse-question' );
		if ( $user <= 0 ) {
			return '<a class="pq-vote" href="' . esc_url( wp_login_url( (string) get_permalink() . '#pq-answers' ) ) . '" aria-label="' . esc_attr( $aria ) . '">' . self::heart() . $text . $num . '</a>';
		}
		$voted = $voted ?? Votes::hasVoted( $type, $id, $user );
		return '<form class="pq-vote-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. wp_nonce_field( 'pq_vote', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="pq_vote" /><input type="hidden" name="type" value="' . esc_attr( $type ) . '" /><input type="hidden" name="id" value="' . $id . '" />'
			. '<button type="submit" class="pq-vote" aria-pressed="' . ( $voted ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $aria ) . '" data-pq-vote="' . esc_attr( $type ) . '" data-pq-id="' . $id . '">' . self::heart() . $text . $num . '</button></form>';
	}
}
