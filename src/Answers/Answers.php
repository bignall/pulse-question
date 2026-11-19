<?php
/**
 * Answers are WordPress comments on questions, so moderation, spam checks (Akismet, if active),
 * replies and the Comments screen all work as usual. On top of that:
 *
 * - Only members can answer (logged in), and only on questions that have been asked.
 * - A member who hasn't been offered the mailing list yet sees the boxes with their first answer.
 * - Answers have a length limit, and no email goes to you for each answer.
 * - Members can report an answer; after enough reports it's hidden until you look at it.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Answers;

use PulseQuestion\Members\Members;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class Answers {

	public const META_REPORTS = '_pulse_question_reports';
	public const META_HIDDEN  = '_pulse_question_hidden_by_reports';

	public static function init(): void {
		add_action( 'init', array( self::class, 'support' ), 20 );
		add_filter( 'comments_open', array( self::class, 'open' ), 10, 2 );
		add_filter( 'preprocess_comment', array( self::class, 'check' ), 5 );
		add_action( 'comment_post', array( self::class, 'created' ), 10, 3 );
		add_filter( 'notify_post_author', array( self::class, 'notifyAuthor' ), 10, 2 );
		add_action( 'delete_comment', array( self::class, 'deleted' ) );
		add_action( 'before_delete_post', array( self::class, 'questionDeleted' ) );
		add_action( 'deleted_user', array( Votes::class, 'forgetUser' ) );
	}

	public static function support(): void {
		add_post_type_support( Questions::POST_TYPE, 'comments' );
	}

	public static function isAnswer( \WP_Comment $comment ): bool {
		return in_array( $comment->comment_type, array( '', 'comment' ), true ) && get_post_type( (int) $comment->comment_post_ID ) === Questions::POST_TYPE;
	}

	/** Answers are open on asked questions while the setting is on. */
	public static function open( bool $open, int|string $post_id ): bool {
		if ( get_post_type( (int) $post_id ) !== Questions::POST_TYPE ) {
			return $open;
		}
		return (bool) Settings::get( 'answers_open' ) && 'publish' === get_post_status( (int) $post_id );
	}

	/**
	 * Before an answer is saved: member, mailing list choice, length.
	 *
	 * @param array<string, mixed> $data Comment data.
	 * @return array<string, mixed>
	 */
	public static function check( array $data ): array {
		$post_id = (int) ( $data['comment_post_ID'] ?? 0 );
		if ( get_post_type( $post_id ) !== Questions::POST_TYPE || ! in_array( (string) ( $data['comment_type'] ?? 'comment' ), array( '', 'comment' ), true ) ) {
			return $data;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_die(
				esc_html__( 'Log in to answer. It only takes a minute to join.', 'pulse-question' ),
				esc_html__( 'Log in to answer', 'pulse-question' ),
				array(
					'response'  => 401,
					'back_link' => true,
				)
			);
		}
		if ( ! Members::wasAsked( $user_id ) ) {
			Members::fromForm( $user_id );
		}
		$max  = (int) Settings::get( 'answer_max_length' );
		$text = (string) ( $data['comment_content'] ?? '' );
		if ( $max > 0 && mb_strlen( $text ) > $max ) {
			wp_die(
				/* translators: %s: number of characters. */
				esc_html( sprintf( __( 'Please keep answers under %s characters.', 'pulse-question' ), number_format_i18n( $max ) ) ),
				esc_html__( 'Answer too long', 'pulse-question' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		return $data;
	}

	public static function created( int $comment_id, int|string $approved, array $data ): void {
		$comment = get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment && self::isAnswer( $comment ) ) {
			add_comment_meta( $comment_id, Votes::META, 0, true );
		}
	}

	/** No email to you for every answer (held answers still notify moderators as WordPress is set). */
	public static function notifyAuthor( bool $notify, int|string $comment_id ): bool {
		$comment = get_comment( (int) $comment_id );
		if ( $comment instanceof \WP_Comment && self::isAnswer( $comment ) ) {
			return (bool) Settings::get( 'answer_emails' );
		}
		return $notify;
	}

	public static function deleted( int|string $comment_id ): void {
		Votes::forget( 'answer', (int) $comment_id );
	}

	public static function questionDeleted( int $post_id ): void {
		if ( get_post_type( $post_id ) === Questions::POST_TYPE ) {
			Votes::forget( 'question', $post_id );
		}
	}

	/**
	 * A member reports an answer. Enough reports hide it until a moderator looks.
	 *
	 * @return array{reported: bool, hidden: bool}|\WP_Error
	 */
	public static function report( int $comment_id, int $user_id ): array|\WP_Error {
		$comment = get_comment( $comment_id );
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'pulse_question_report_login', __( 'Log in to report an answer.', 'pulse-question' ) );
		}
		if ( ! $comment instanceof \WP_Comment || ! self::isAnswer( $comment ) || '1' !== $comment->comment_approved ) {
			return new \WP_Error( 'pulse_question_report_target', __( 'That answer can\'t be reported.', 'pulse-question' ) );
		}
		if ( (int) $comment->user_id === $user_id ) {
			return new \WP_Error( 'pulse_question_report_own', __( 'You can\'t report your own answer.', 'pulse-question' ) );
		}
		$reports = array_map( 'intval', (array) get_comment_meta( $comment_id, self::META_REPORTS ) );
		if ( ! in_array( $user_id, $reports, true ) ) {
			add_comment_meta( $comment_id, self::META_REPORTS, $user_id );
			$reports[] = $user_id;
		}
		$threshold = (int) Settings::get( 'report_threshold' );
		$hidden    = false;
		if ( $threshold > 0 && count( $reports ) >= $threshold ) {
			wp_set_comment_status( $comment_id, 'hold' );
			update_comment_meta( $comment_id, self::META_HIDDEN, 1 );
			$hidden = true;
			/**
			 * Fires when reports hide an answer.
			 *
			 * @param int $comment_id The answer.
			 */
			do_action( 'pulse_question_answer_hidden', $comment_id );
		}
		return array(
			'reported' => true,
			'hidden'   => $hidden,
		);
	}

	/** Answers waiting for a moderator. */
	public static function heldCount(): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
				WHERE p.post_type = %s AND c.comment_approved = '0' AND c.comment_type IN ('', 'comment')",
				Questions::POST_TYPE
			)
		);
	}

	public static function moderationUrl(): string {
		return admin_url( 'edit-comments.php?comment_status=moderated&post_type=' . Questions::POST_TYPE );
	}
}
