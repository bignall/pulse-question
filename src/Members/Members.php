<?php
/**
 * Members: answering needs an account, and every new member is offered the mailing list.
 *
 * The sign-up form (wp-login.php?action=register) gets a "send me the question of the day" box
 * and a marketing box, both optional and ticked by default (a setting). Members who signed up before
 * (or elsewhere) see the same boxes once, with their first answer. The choice is recorded on the
 * user and passed to MailingList.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Members;

use PulseQuestion\Settings;

final class Members {

	public const META_JOINED    = '_pq_joined';
	public const META_MARKETING = '_pq_marketing';
	/** When the member was offered the boxes, whatever they chose. */
	public const META_ASKED = '_pq_join_asked';

	public static function init(): void {
		add_action( 'register_form', array( self::class, 'signupFields' ) );
		add_action( 'user_register', array( self::class, 'signedUp' ) );
	}

	public static function hasJoined( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_JOINED, true );
	}

	/** Whether the member has seen the boxes (and so isn't offered them again). */
	public static function wasAsked( int $user_id ): bool {
		return self::hasJoined( $user_id ) || '' !== (string) get_user_meta( $user_id, self::META_ASKED, true );
	}

	/** Record the member's choice and subscribe them to what they chose. */
	public static function join( int $user_id, bool $marketing, bool $list = true ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::META_ASKED, current_time( 'mysql', true ) );
		update_user_meta( $user_id, self::META_MARKETING, $marketing ? 1 : 0 );
		if ( $list ) {
			update_user_meta( $user_id, self::META_JOINED, current_time( 'mysql', true ) );
		}
		if ( ! $list && ! $marketing ) {
			return;
		}
		MailingList::subscribe( $user_id, $marketing, $list );
		/**
		 * Fires when a member chooses the daily question email, marketing email or both.
		 *
		 * @param int  $user_id   The member.
		 * @param bool $marketing Whether they chose marketing email.
		 * @param bool $list      Whether they chose the daily question email.
		 */
		do_action( 'pulse_question_member_joined', $user_id, $marketing, $list );
	}

	/** The two boxes (ticked by default), for the sign-up form and the first answer. */
	public static function fields(): string {
		$list      = (string) Settings::get( 'list_label' );
		$marketing = (string) Settings::get( 'marketing_label' );
		$checked   = Settings::get( 'join_prechecked' ) ? ' checked' : '';
		$html      = '<input type="hidden" name="pq_join_offered" value="1" />';
		$html     .= '<p class="pq-join"><label><input type="checkbox" name="pq_join" value="1"' . $checked . ' /> ' . esc_html( $list ) . '</label></p>';
		if ( '' !== $marketing ) {
			$html .= '<p class="pq-join"><label><input type="checkbox" name="pq_marketing" value="1"' . $checked . ' /> ' . esc_html( $marketing ) . '</label></p>';
		}
		return $html;
	}

	public static function signupFields(): void {
		echo self::fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fields().
	}

	/** Apply the boxes from a submitted form, if the form showed them. */
	public static function fromForm( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WordPress's own registration and comment forms.
		if ( ! empty( $_POST['pq_join_offered'] ) ) {
			self::join( $user_id, ! empty( $_POST['pq_marketing'] ), ! empty( $_POST['pq_join'] ) );
		}
		// phpcs:enable
	}

	public static function signedUp( int $user_id ): void {
		self::fromForm( $user_id );
	}
}
