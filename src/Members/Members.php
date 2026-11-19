<?php
/**
 * Members: answering needs an account, and joining the Pulse Question mailing list.
 *
 * The sign-up form (wp-login.php?action=register) gets a required "join the mailing list" box
 * and an optional marketing box. Members who signed up before (or elsewhere) tick the same
 * boxes with their first answer. Joining is recorded on the user and passed to MailingList.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Members;

use PulseQuestion\Settings;

final class Members {

	public const META_JOINED    = '_pq_joined';
	public const META_MARKETING = '_pq_marketing';

	public static function init(): void {
		add_action( 'register_form', array( self::class, 'signupFields' ) );
		add_filter( 'registration_errors', array( self::class, 'signupCheck' ), 10, 3 );
		add_action( 'user_register', array( self::class, 'signedUp' ) );
	}

	public static function hasJoined( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_JOINED, true );
	}

	/** Record joining and subscribe to the list (and marketing, if chosen). */
	public static function join( int $user_id, bool $marketing ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::META_JOINED, current_time( 'mysql', true ) );
		update_user_meta( $user_id, self::META_MARKETING, $marketing ? 1 : 0 );
		MailingList::subscribe( $user_id, $marketing );
		/**
		 * Fires when a member joins the Pulse Question mailing list.
		 *
		 * @param int  $user_id   The member.
		 * @param bool $marketing Whether they also chose marketing email.
		 */
		do_action( 'pulse_question_member_joined', $user_id, $marketing );
	}

	/** The two boxes, for the sign-up form and the first answer. */
	public static function fields(): string {
		$list      = (string) Settings::get( 'list_label' );
		$marketing = (string) Settings::get( 'marketing_label' );
		$html      = '<p class="pq-join"><label><input type="checkbox" name="pq_join" value="1" required /> ' . esc_html( $list ) . '</label></p>';
		if ( '' !== $marketing ) {
			$html .= '<p class="pq-join pq-join--optional"><label><input type="checkbox" name="pq_marketing" value="1" /> ' . esc_html( $marketing ) . '</label></p>';
		}
		return $html;
	}

	public static function signupFields(): void {
		echo self::fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fields().
	}

	public static function signupCheck( \WP_Error $errors, string $login, string $email ): \WP_Error {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress's registration form.
		if ( empty( $_POST['pq_join'] ) ) {
			$errors->add( 'pq_join', '<strong>' . esc_html__( 'Error:', 'pulse-question' ) . '</strong> ' . esc_html__( 'Please tick the box to join the mailing list. Members get the daily question by email.', 'pulse-question' ) );
		}
		return $errors;
	}

	public static function signedUp( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WordPress's registration form.
		if ( ! empty( $_POST['pq_join'] ) ) {
			self::join( $user_id, ! empty( $_POST['pq_marketing'] ) );
		}
		// phpcs:enable
	}
}
