<?php
/**
 * The built-in sender, for sites without FluentCRM: emails members who ticked the daily email
 * box, through wp_mail (so an SMTP or email-service plugin is used if the site has one), in
 * batches of 40 a minute so the host isn't flooded.
 *
 * Each email has an unsubscribe link and the List-Unsubscribe headers mail apps use for their
 * own unsubscribe button. Unsubscribing removes the daily email choice from the member; they
 * keep their account and answers.
 *
 * Fine for a few hundred members. For more, use FluentCRM with a proper email service.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Email;

use PulseQuestion\Members\Members;

final class Builtin {

	public const BATCH_HOOK = 'pulse_question_email_batch';
	/** The email being sent: subject, preheader, body, day. */
	private const JOB = 'pulse_question_email_job';

	public static function init(): void {
		add_action( self::BATCH_HOOK, array( self::class, 'batch' ) );
		add_action( 'init', array( self::class, 'unsubscribeRequest' ), 1 );
	}

	public static function batchSize(): int {
		/** @param int $size Emails per minute for the built-in sender. Default 40. */
		return max( 1, (int) apply_filters( 'pulse_question_email_batch_size', 40 ) );
	}

	/** Members who chose the daily email. */
	public static function recipientCount(): int {
		$query = new \WP_User_Query(
			array(
				'meta_key'    => Members::META_JOINED, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'count_total' => true,
				'number'      => 1,
				'fields'      => 'ID',
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * Start sending: the first batch now, the rest a minute apart.
	 *
	 * @param array{subject: string, preheader: string, body: string} $content The email.
	 * @return array{ok: bool, message: string}
	 */
	public static function start( string $day, array $content ): array {
		$total = self::recipientCount();
		if ( 0 === $total ) {
			return array(
				'ok'      => false,
				'message' => __( 'No members have chosen the daily email yet, so there was no one to send to.', 'pulse-question' ),
			);
		}
		update_option(
			self::JOB,
			array(
				'day'       => $day,
				'subject'   => $content['subject'],
				'preheader' => $content['preheader'],
				'body'      => $content['body'],
			),
			false
		);
		$state               = DailyEmail::state();
		$state['recipients'] = $total;
		$state['sent']       = 0;
		$state['failed']     = 0;
		$state['last_user']  = 0;
		$state['status']     = 'sending';
		DailyEmail::saveState( $state );
		self::batch();
		return array(
			'ok'      => true,
			/* translators: %s: number of people. */
			'message' => sprintf( _n( 'Sending today\'s email to %s member.', 'Sending today\'s email to %s members, 40 a minute.', $total, 'pulse-question' ), number_format_i18n( $total ) ),
		);
	}

	/** Pick up a send that stopped (WP-Cron missed the next batch). */
	public static function resume(): void {
		$state = DailyEmail::state();
		if ( 'sending' === ( $state['status'] ?? '' ) && (int) ( $state['batch_at'] ?? 0 ) < time() - 5 * MINUTE_IN_SECONDS ) {
			self::batch();
		}
	}

	public static function batch(): void {
		$job   = get_option( self::JOB );
		$state = DailyEmail::state();
		if ( ! is_array( $job ) || 'sending' !== ( $state['status'] ?? '' ) || ( $state['day'] ?? '' ) !== ( $job['day'] ?? '' ) ) {
			return;
		}
		$state['batch_at'] = time();
		DailyEmail::saveState( $state );

		$users = self::after( (int) ( $state['last_user'] ?? 0 ) );

		foreach ( $users as $user ) {
			$unsub = self::unsubscribeUrl( (int) $user->ID );
			$ok    = wp_mail(
				(string) $user->user_email,
				(string) $job['subject'],
				DailyEmail::wrap( $job, $unsub ),
				DailyEmail::headers( $unsub )
			);
			$state['last_user'] = (int) $user->ID;
			if ( $ok ) {
				$state['sent'] = (int) ( $state['sent'] ?? 0 ) + 1;
			} else {
				$state['failed'] = (int) ( $state['failed'] ?? 0 ) + 1;
			}
		}

		if ( count( $users ) < self::batchSize() ) {
			$state['status'] = (int) ( $state['sent'] ?? 0 ) > 0 || 0 === (int) ( $state['failed'] ?? 0 ) ? 'sent' : 'failed';
			if ( 'failed' === $state['status'] ) {
				$state['error'] = __( 'WordPress couldn\'t send any of the emails. Check the site can send email (an SMTP plugin helps).', 'pulse-question' );
			}
			DailyEmail::saveState( $state );
			delete_option( self::JOB );
			DailyEmail::log(
				$state,
				'sent' === $state['status'],
				'sent' === $state['status']
					/* translators: 1: sent, 2: failed. */
					? sprintf( __( 'Sent to %1$s members (%2$s failed).', 'pulse-question' ), number_format_i18n( (int) $state['sent'] ), number_format_i18n( (int) ( $state['failed'] ?? 0 ) ) )
					: (string) $state['error']
			);
			return;
		}
		DailyEmail::saveState( $state );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BATCH_HOOK );
	}

	/** @return list<object> The next batch of members after this user id. */
	private static function after( int $last ): array {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d",
				Members::META_JOINED,
				$last,
				self::batchSize()
			)
		);
		$out = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$user = get_userdata( $id );
			if ( $user ) {
				$out[] = (object) array(
					'ID'         => $id,
					'user_email' => $user->user_email,
				);
			}
		}
		return $out;
	}

	public static function unsubscribeUrl( int $user_id ): string {
		return add_query_arg(
			array(
				'pulse_question_unsubscribe' => $user_id,
				'k'              => self::key( $user_id ),
			),
			home_url( '/' )
		);
	}

	private static function key( int $user_id ): string {
		return substr( hash_hmac( 'sha256', 'pq-unsubscribe|' . $user_id, wp_salt( 'auth' ) ), 0, 24 );
	}

	/** The unsubscribe link (GET) and mail apps' one-click unsubscribe (POST). */
	public static function unsubscribeRequest(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed key is the check.
		// pq_unsubscribe: links in emails sent before 0.6.0.
		$param = isset( $_GET['pulse_question_unsubscribe'] ) ? 'pulse_question_unsubscribe' : 'pq_unsubscribe';
		if ( ! isset( $_GET[ $param ], $_GET['k'] ) ) {
			return;
		}
		$user_id = absint( $_GET[ $param ] );
		$key     = sanitize_text_field( wp_unslash( (string) $_GET['k'] ) );
		// phpcs:enable
		if ( $user_id <= 0 || ! hash_equals( self::key( $user_id ), $key ) ) {
			wp_die( esc_html__( 'This unsubscribe link isn\'t valid.', 'pulse-question' ), esc_html__( 'Unsubscribe', 'pulse-question' ), array( 'response' => 400 ) );
		}
		delete_user_meta( $user_id, Members::META_JOINED );
		update_user_meta( $user_id, Members::META_ASKED, current_time( 'mysql', true ) );
		/**
		 * Fires when a member unsubscribes from the built-in daily email.
		 *
		 * @param int $user_id The member.
		 */
		do_action( 'pulse_question_unsubscribed', $user_id );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 200 );
			exit;
		}
		wp_die(
			'<p>' . esc_html__( 'You won\'t get the daily question by email any more.', 'pulse-question' ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the site', 'pulse-question' ) . '</a></p>',
			esc_html__( 'Unsubscribed', 'pulse-question' ),
			array( 'response' => 200 )
		);
	}
}
