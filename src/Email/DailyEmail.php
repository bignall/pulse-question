<?php
/**
 * The daily email: today's question, its background, an Answer button and yesterday's top
 * answers, sent once a day soon after the new question goes up.
 *
 * How it's sent follows Settings → Members: with FluentCRM (on this site or another), as a
 * FluentCRM campaign to the daily email lists; otherwise by the built-in sender, to members who
 * ticked the daily email box. One email per question day. A failed send is tried again on the
 * hourly check (up to five tries, half an hour apart), and To-do Hub gets a task until it works.
 *
 * Turning it on starts with the next new question, so nobody gets an email in the middle of the
 * day by surprise. "Send now" in Settings sends today's straight away.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Email;

use PulseQuestion\Answers\Votes;
use PulseQuestion\Daily;
use PulseQuestion\Front\Render;
use PulseQuestion\Members\MailingList;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class DailyEmail {

	public const HOOK  = 'pq_daily_email';
	public const STATE = 'pq_email_state';
	public const LOG   = 'pq_email_log';
	private const LOCK = 'pq_email_lock';

	private const MAX_TRIES = 5;

	public static function init(): void {
		add_action( 'pulse_question_new_day', array( self::class, 'onNewDay' ), 20, 2 );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( Daily::CRON_HOOK, array( self::class, 'catchUp' ), 30 );
		Builtin::init();
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'email_enabled' );
	}

	/** fluentcrm, fluentcrm_remote or builtin. */
	public static function method(): string {
		$provider = MailingList::provider();
		return 'none' === $provider ? 'builtin' : $provider;
	}

	/** Whether automatic sending covers this question day (it starts the day after it's turned on). */
	public static function covers( string $day ): bool {
		$from = (string) Settings::get( 'email_from' );
		return self::enabled() && ( '' === $from || $day >= $from );
	}

	/** A new question went up: send a minute later, from WP-Cron, so the visitor isn't kept waiting. */
	public static function onNewDay( int $id, string $day ): void {
		if ( self::covers( $day ) && ! self::doneFor( $day ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $day ) );
		}
	}

	public static function run( string $day = '' ): void {
		$today = Daily::current();
		if ( null === $today || ( '' !== $day && $day !== $today['day'] ) || ! self::covers( $today['day'] ) ) {
			return;
		}
		self::send();
	}

	/** Hourly: send today's if it never went (WP-Cron missed it), retry a failure, keep a built-in send going. */
	public static function catchUp(): void {
		$today = Daily::stored();
		if ( null === $today || ! self::covers( $today['day'] ) ) {
			return;
		}
		$state = self::state();
		if ( ( $state['day'] ?? '' ) !== $today['day'] ) {
			self::send();
		} elseif ( 'failed' === ( $state['status'] ?? '' ) && (int) ( $state['tries'] ?? 0 ) < self::MAX_TRIES && (int) ( $state['last_try'] ?? 0 ) < time() - 30 * MINUTE_IN_SECONDS ) {
			self::send();
		} elseif ( 'sending' === ( $state['status'] ?? '' ) && 'builtin' === ( $state['method'] ?? '' ) ) {
			Builtin::resume();
		}
	}

	/** @return array<string, mixed> Today's send: day, question, method, status, tries, last_try, error, campaign, recipients. */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	public static function saveState( array $state ): void {
		update_option( self::STATE, $state, false );
	}

	public static function doneFor( string $day ): bool {
		$state = self::state();
		return ( $state['day'] ?? '' ) === $day && in_array( $state['status'] ?? '', array( 'sent', 'sending' ), true );
	}

	/**
	 * Send today's email now (unless it has already gone).
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function send(): array {
		$today = Daily::current();
		if ( null === $today ) {
			return array(
				'ok'      => false,
				'message' => __( 'There\'s no question of the day yet.', 'pulse-question' ),
			);
		}
		if ( self::doneFor( $today['day'] ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Today\'s email has already been sent.', 'pulse-question' ),
			);
		}
		// One send at a time.
		$lock = get_option( self::LOCK );
		if ( false !== $lock && (int) $lock < time() - 10 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK );
		}
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The email is being sent right now.', 'pulse-question' ),
			);
		}
		try {
			$state = self::state();
			if ( ( $state['day'] ?? '' ) !== $today['day'] ) {
				$state = array(
					'day'   => $today['day'],
					'tries' => 0,
				);
			}
			$method            = self::method();
			$state['question'] = $today['id'];
			$state['method']   = $method;
			$state['tries']    = (int) ( $state['tries'] ?? 0 ) + 1;
			$state['last_try'] = time();
			$state['error']    = '';
			$content           = self::content( $today['id'], $today['day'] );

			if ( 'builtin' === $method ) {
				$state['status'] = 'sending';
				self::saveState( $state );
				$started = Builtin::start( $today['day'], $content );
				$state   = self::state();
				if ( ! $started['ok'] ) {
					$state['status'] = 'failed';
					$state['error']  = $started['message'];
					self::saveState( $state );
				}
				self::log( $state, $started['ok'], $started['message'] );
				return $started;
			}

			$title  = sprintf( '%s %s', wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), $today['day'] );
			$result = FluentCampaign::send(
				array(
					'subject'   => $content['subject'],
					'preheader' => $content['preheader'],
					'body'      => $content['body'],
				),
				'' !== trim( $title ) ? $title : 'Pulse Question ' . $today['day'],
				(int) ( $state['campaign'] ?? 0 ),
				static function ( int $id ) use ( &$state ): void {
					$state['campaign'] = $id;
					self::saveState( $state );
				}
			);
			$state['campaign']   = $result['campaign'];
			$state['recipients'] = $result['recipients'];
			$state['status']     = $result['ok'] ? 'sent' : 'failed';
			$state['error']      = $result['error'];
			self::saveState( $state );
			$message = $result['ok']
				/* translators: %s: number of people. */
				? sprintf( _n( 'FluentCRM is sending today\'s email to %s person.', 'FluentCRM is sending today\'s email to %s people.', $result['recipients'], 'pulse-question' ), number_format_i18n( $result['recipients'] ) )
				: $result['error'];
			self::log( $state, $result['ok'], $message );
			return array(
				'ok'      => $result['ok'],
				'message' => $message,
			);
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** @param array<string, mixed> $state Send state. */
	public static function log( array $state, bool $ok, string $detail ): void {
		$log = self::history();
		array_unshift(
			$log,
			array(
				'time'     => time(),
				'day'      => (string) ( $state['day'] ?? '' ),
				'question' => (int) ( $state['question'] ?? 0 ),
				'method'   => (string) ( $state['method'] ?? '' ),
				'ok'       => $ok,
				'detail'   => $detail,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 14 ), false );
		/**
		 * Fires after each try at sending the daily email.
		 *
		 * @param bool   $ok     Whether it worked (or, built-in, started).
		 * @param string $detail What happened.
		 */
		do_action( 'pulse_question_email_sent', $ok, $detail );
	}

	/** @return list<array{time: int, day: string, question: int, method: string, ok: bool, detail: string}> */
	public static function history(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * The email for a question: subject, preview text, and body HTML (the content only; the
	 * built-in sender wraps it, FluentCRM puts it in its own template and footer).
	 *
	 * @return array{subject: string, preheader: string, body: string, url: string}
	 */
	public static function content( int $post_id, string $day ): array {
		$post     = get_post( $post_id );
		$title    = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$url      = (string) get_permalink( $post );
		$site     = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$date     = '' !== $day ? Render::dayLabel( $day ) : '';
		$subject  = strtr(
			(string) Settings::get( 'email_subject' ),
			array(
				'{question}' => $title,
				'{date}'     => $date,
				'{site}'     => $site,
			)
		);
		$subject  = '' !== trim( $subject ) ? trim( $subject ) : $title;
		$plain_bg = trim( wp_strip_all_tags( (string) $post->post_content ) );
		$preheader = '' !== $plain_bg ? wp_html_excerpt( $plain_bg, 140, '…' ) : sprintf(
			/* translators: %s: site name. */
			__( 'Today\'s question from %s', 'pulse-question' ),
			$site
		);
		$label  = (string) Settings::get( 'label' );
		$accent = self::color( 'accent' );
		$text   = self::color( 'text' );
		$muted  = self::color( 'muted' );
		$font   = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

		$body  = '';
		$intro = trim( (string) Settings::get( 'email_intro' ) );
		if ( '' !== $intro ) {
			$body .= '<p style="margin:0 0 20px;font-family:' . esc_attr( $font ) . ';font-size:16px;line-height:1.5;color:' . esc_attr( $text ) . ';">' . nl2br( esc_html( $intro ) ) . '</p>';
		}
		$body .= '<p style="margin:0 0 8px;font-family:' . esc_attr( $font ) . ';font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:' . esc_attr( $muted ) . ';">'
			. esc_html( trim( $label . ( '' !== $label && '' !== $date ? ' · ' : '' ) . $date ) ) . '</p>';
		$body .= '<h1 style="margin:0 0 16px;font-family:' . esc_attr( $font ) . ';font-size:28px;line-height:1.25;font-weight:700;color:' . esc_attr( $text ) . ';"><a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $text ) . ';text-decoration:none;">' . esc_html( $title ) . '</a></h1>';
		if ( '' !== $plain_bg ) {
			$body .= '<div style="margin:0 0 24px;font-family:' . esc_attr( $font ) . ';font-size:16px;line-height:1.6;color:' . esc_attr( $text ) . ';">' . wp_kses_post( wpautop( (string) $post->post_content ) ) . '</div>';
		}
		$body .= '<p style="margin:0 0 28px;"><a href="' . esc_url( $url . '#pq-answers' ) . '" style="display:inline-block;padding:14px 28px;border-radius:999px;background:' . esc_attr( $accent ) . ';color:#ffffff;font-family:' . esc_attr( $font ) . ';font-size:17px;font-weight:600;text-decoration:none;">'
			. esc_html( (string) Settings::get( 'email_button' ) ) . '</a></p>';
		$body .= self::yesterday( $post_id, $day, $font, $text, $muted, $accent );

		/**
		 * The daily email before it's sent.
		 *
		 * @param array{subject: string, preheader: string, body: string, url: string} $email   The email.
		 * @param int                                                                   $post_id The question.
		 */
		$email = apply_filters(
			'pulse_question_email',
			array(
				'subject'   => $subject,
				'preheader' => $preheader,
				'body'      => $body,
				'url'       => $url,
			),
			$post_id
		);
		return $email;
	}

	/** Yesterday's question with its top answers, if it had any. */
	private static function yesterday( int $today_id, string $day, string $font, string $text, string $muted, string $accent ): string {
		$count = (int) Settings::get( 'email_top_answers' );
		if ( $count <= 0 || ! Settings::get( 'answers_open' ) ) {
			return '';
		}
		// The question asked most recently before today's.
		$best = 0;
		$when = '';
		foreach ( Questions::asked() as $id => $last ) {
			if ( $id === $today_id ) {
				continue;
			}
			foreach ( Questions::askedDays( $id ) as $asked ) {
				// Latest day before today; on a tie (Ask now used that day), the newer question.
				if ( ( '' === $day || $asked < $day ) && ( $asked > $when || ( $asked === $when && $id > $best ) ) ) {
					$best = $id;
					$when = $asked;
				}
			}
		}
		if ( 0 === $best ) {
			return '';
		}
		$answers = get_comments(
			array(
				'post_id'  => $best,
				'status'   => 'approve',
				'parent'   => 0,
				'type'     => 'comment',
				'number'   => $count,
				'meta_key' => Votes::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'  => array(
					'meta_value_num'   => 'DESC',
					'comment_date_gmt' => 'ASC',
				),
			)
		);
		if ( ! $answers ) {
			return '';
		}
		$url  = (string) get_permalink( $best );
		$html = '<div style="border-top:1px solid #e8e2dc;margin:4px 0 0;padding:24px 0 0;">';
		$html .= '<p style="margin:0 0 6px;font-family:' . esc_attr( $font ) . ';font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:' . esc_attr( $muted ) . ';">' . esc_html__( 'Yesterday\'s question', 'pulse-question' ) . '</p>';
		$html .= '<p style="margin:0 0 16px;font-family:' . esc_attr( $font ) . ';font-size:19px;line-height:1.35;font-weight:600;"><a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $text ) . ';text-decoration:none;">' . esc_html( wp_specialchars_decode( get_the_title( $best ), ENT_QUOTES ) ) . '</a></p>';
		foreach ( $answers as $answer ) {
			$votes = (int) get_comment_meta( (int) $answer->comment_ID, Votes::META, true );
			$html .= '<div style="margin:0 0 14px;padding:12px 16px;border-left:3px solid ' . esc_attr( $accent ) . ';background:#faf7f4;">';
			$html .= '<p style="margin:0 0 6px;font-family:' . esc_attr( $font ) . ';font-size:15px;line-height:1.5;color:' . esc_attr( $text ) . ';">' . esc_html( wp_trim_words( wp_strip_all_tags( $answer->comment_content ), 45, '…' ) ) . '</p>';
			$html .= '<p style="margin:0;font-family:' . esc_attr( $font ) . ';font-size:13px;color:' . esc_attr( $muted ) . ';">' . esc_html( $answer->comment_author )
				/* translators: %s: number of likes. */
				. ( $votes > 0 ? ' · ' . esc_html( sprintf( _n( '%s like', '%s likes', $votes, 'pulse-question' ), number_format_i18n( $votes ) ) ) : '' ) . '</p>';
			$html .= '</div>';
		}
		$total = (int) get_comments_number( $best );
		$html .= '<p style="margin:4px 0 0;font-family:' . esc_attr( $font ) . ';font-size:15px;"><a href="' . esc_url( $url . '#pq-answers' ) . '" style="color:' . esc_attr( $accent ) . ';">'
			/* translators: %s: number of answers. */
			. esc_html( sprintf( _n( 'Read %s answer', 'Read all %s answers', $total, 'pulse-question' ), number_format_i18n( $total ) ) ) . ' &rarr;</a></p>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * The full HTML email around the content (built-in sender, preview and test).
	 *
	 * @param array{subject: string, preheader: string, body: string} $email The content.
	 */
	public static function wrap( array $email, string $unsubscribe = '' ): string {
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$font   = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
		$muted  = self::color( 'muted' );
		$footer = sprintf(
			/* translators: %s: site name with link. */
			esc_html__( 'You\'re getting this because you joined %s.', 'pulse-question' ),
			'<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . esc_attr( $muted ) . ';">' . esc_html( $site ) . '</a>'
		);
		if ( '' !== $unsubscribe ) {
			$footer .= ' <a href="' . esc_url( $unsubscribe ) . '" style="color:' . esc_attr( $muted ) . ';">' . esc_html__( 'Unsubscribe', 'pulse-question' ) . '</a>';
		}
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $email['subject'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f6f2ee;">'
			. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html( $email['preheader'] ) . '</div>'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f2ee;"><tr><td align="center" style="padding:28px 12px;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;"><tr><td style="padding:32px 32px 28px;">'
			. $email['body']
			. '</td></tr></table>'
			. '<p style="max-width:600px;margin:18px auto 0;font-family:' . esc_attr( $font ) . ';font-size:12px;line-height:1.5;color:' . esc_attr( $muted ) . ';">' . $footer . '</p>'
			. '</td></tr></table></body></html>';
	}

	/**
	 * Headers for the built-in sender and test emails: HTML, and the From in Settings if set.
	 *
	 * @return list<string>
	 */
	public static function headers( string $unsubscribe = '' ): array {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$address = sanitize_email( (string) Settings::get( 'email_from_address' ) );
		if ( '' !== $address ) {
			$name      = trim( (string) Settings::get( 'email_from_name' ) );
			$name      = '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = 'From: ' . str_replace( array( '"', "\r", "\n" ), '', $name ) . ' <' . $address . '>';
		}
		if ( '' !== $unsubscribe ) {
			$headers[] = 'List-Unsubscribe: <' . $unsubscribe . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}
		return $headers;
	}

	/** Email colours; filter pulse_question_email_colors to change them. */
	public static function color( string $key ): string {
		$colors = array(
			'accent' => \PulseQuestion\Branding::darken( \PulseQuestion\Branding::accent(), 0.1 ),
			'text'   => '#1d1d1f',
			'muted'  => '#6b6b70',
		);
		/** @param array<string, string> $colors accent (button, links), text, muted. */
		$colors = (array) apply_filters( 'pulse_question_email_colors', $colors );
		return (string) ( $colors[ $key ] ?? '#000000' );
	}
}
