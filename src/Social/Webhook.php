<?php
/**
 * Posts each new question to social media through a webhook: Zapier, Make, n8n, Pabbly,
 * IFTTT or your own script receive the question, its link, a ready-written post and the share
 * image, and post it wherever you've connected (Facebook, Instagram, LinkedIn, X, Bluesky,
 * Threads, Pinterest, Buffer…).
 *
 * Sent a minute and a half after the new question goes up, once per question day. A failure is
 * tried again on the hourly check (up to five tries) and To-do Hub gets a task until it works.
 * Each request is signed: X-Pulse-Question-Signature: sha256=HMAC-SHA256(body, secret).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Social;

use PulseQuestion\Daily;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class Webhook {

	public const HOOK  = 'pq_social_post';
	public const STATE = 'pq_social_state';
	public const LOG   = 'pq_social_log';

	private const MAX_TRIES = 5;

	public static function init(): void {
		add_action( 'pulse_question_new_day', array( self::class, 'onNewDay' ), 30, 2 );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( Daily::CRON_HOOK, array( self::class, 'catchUp' ), 35 );
	}

	public static function enabled(): bool {
		return '' !== (string) Settings::get( 'social_webhook_url' );
	}

	/** Automatic posts start with the next question after the webhook is set. */
	public static function covers( string $day ): bool {
		$from = (string) Settings::get( 'social_from' );
		return self::enabled() && ( '' === $from || $day >= $from );
	}

	public static function onNewDay( int $id, string $day ): void {
		if ( self::covers( $day ) && ! self::doneFor( $day ) ) {
			wp_schedule_single_event( time() + 90, self::HOOK, array( $day ) );
		}
	}

	public static function run( string $day = '' ): void {
		$today = Daily::current();
		if ( null !== $today && ( '' === $day || $day === $today['day'] ) && self::covers( $today['day'] ) && ! self::doneFor( $today['day'] ) ) {
			self::post();
		}
	}

	public static function catchUp(): void {
		$today = Daily::stored();
		if ( null === $today || ! self::covers( $today['day'] ) ) {
			return;
		}
		$state = self::state();
		if ( ( $state['day'] ?? '' ) !== $today['day'] ) {
			self::post();
		} elseif ( 'failed' === ( $state['status'] ?? '' ) && (int) ( $state['tries'] ?? 0 ) < self::MAX_TRIES && (int) ( $state['last_try'] ?? 0 ) < time() - 30 * MINUTE_IN_SECONDS ) {
			self::post();
		}
	}

	/** @return array<string, mixed> */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	public static function doneFor( string $day ): bool {
		$state = self::state();
		return ( $state['day'] ?? '' ) === $day && 'sent' === ( $state['status'] ?? '' );
	}

	/**
	 * What the webhook receives for a question.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload( int $post_id, string $day, bool $test = false ): array {
		$post   = get_post( $post_id );
		$title  = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$url    = (string) get_permalink( $post );
		$image  = ShareImage::forQuestion( $post_id );
		$bg     = trim( wp_strip_all_tags( (string) $post->post_content ) );
		$terms  = get_the_terms( $post_id, Questions::TAXONOMY );
		$tags   = self::hashtags();
		$data   = array(
			'event'        => 'question_of_the_day',
			'test'         => $test,
			'date'         => $day,
			'question'     => $title,
			'background'   => $bg,
			'url'          => $url,
			'text'         => self::text( $title, $url, $bg, $tags ),
			'hashtags'     => $tags,
			'category'     => is_array( $terms ) && $terms ? $terms[0]->name : '',
			'image_url'    => $image['url'] ?? '',
			'image_width'  => $image['width'] ?? 0,
			'image_height' => $image['height'] ?? 0,
			'image_alt'    => $title,
			'site'         => array(
				'name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
		);
		/**
		 * The data sent to the social webhook.
		 *
		 * @param array<string, mixed> $data    The payload.
		 * @param int                  $post_id The question.
		 */
		return (array) apply_filters( 'pulse_question_social_payload', $data, $post_id );
	}

	/** @return list<string> Hashtags from Settings, each starting with #. */
	public static function hashtags(): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', (string) Settings::get( 'social_hashtags' ) ) ?: array() as $tag ) {
			$tag = ltrim( trim( $tag ), '#' );
			if ( '' !== $tag ) {
				$out[] = '#' . $tag;
			}
		}
		return $out;
	}

	/** @param list<string> $tags Hashtags. */
	public static function text( string $question, string $url, string $background, array $tags ): string {
		$text = strtr(
			(string) Settings::get( 'social_text' ),
			array(
				'{question}'   => $question,
				'{url}'        => $url,
				'{background}' => $background,
				'{hashtags}'   => implode( ' ', $tags ),
				'{site}'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Send today's question to the webhook.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function post( bool $test = false ): array {
		$today = Daily::current();
		if ( null === $today ) {
			return array(
				'ok'      => false,
				'message' => __( 'There\'s no question of the day yet.', 'pulse-question' ),
			);
		}
		$url = (string) Settings::get( 'social_webhook_url' );
		if ( '' === $url ) {
			return array(
				'ok'      => false,
				'message' => __( 'Add a webhook address first.', 'pulse-question' ),
			);
		}
		$body     = (string) wp_json_encode( self::payload( $today['id'], $today['day'], $test ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'                => 'application/json',
					'X-Pulse-Question-Signature'  => 'sha256=' . hash_hmac( 'sha256', $body, self::secret() ),
					'X-Pulse-Question-Test'       => $test ? '1' : '0',
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			$ok      = false;
			/* translators: %s: error. */
			$message = sprintf( __( 'Couldn\'t reach the webhook: %s', 'pulse-question' ), $response->get_error_message() );
		} else {
			$code    = (int) wp_remote_retrieve_response_code( $response );
			$ok      = $code >= 200 && $code < 300;
			$message = $ok
				? __( 'The webhook accepted today\'s question.', 'pulse-question' )
				/* translators: 1: HTTP status, 2: start of the answer. */
				: sprintf( __( 'The webhook answered HTTP %1$d: %2$s', 'pulse-question' ), $code, wp_html_excerpt( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 160, '…' ) );
		}
		if ( ! $test ) {
			$state = self::state();
			if ( ( $state['day'] ?? '' ) !== $today['day'] ) {
				$state = array(
					'day'   => $today['day'],
					'tries' => 0,
				);
			}
			$state['tries']    = (int) ( $state['tries'] ?? 0 ) + 1;
			$state['last_try'] = time();
			$state['status']   = $ok ? 'sent' : 'failed';
			$state['error']    = $ok ? '' : $message;
			update_option( self::STATE, $state, false );
		}
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'   => time(),
				'day'    => $today['day'],
				'test'   => $test,
				'ok'     => $ok,
				'detail' => $message,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 14 ), false );
		if ( ! $test ) {
			/**
			 * Fires after each automatic social post.
			 *
			 * @param bool   $ok      Whether the webhook accepted it.
			 * @param string $message What happened.
			 */
			do_action( 'pulse_question_social_posted', $ok, $message );
		}
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}

	/** @return list<array{time: int, day: string, test: bool, ok: bool, detail: string}> */
	public static function history(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/** The signing secret, made the first time it's needed. */
	public static function secret(): string {
		$secret = (string) get_option( 'pq_social_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 40, false );
			update_option( 'pq_social_secret', $secret, false );
		}
		return $secret;
	}
}
