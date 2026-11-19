<?php
/**
 * Writes questions with Claude (the Anthropic API), on demand or to keep the queue topped up.
 *
 * One request per batch: the guidance from Settings, the categories in use and the most recent
 * questions (so it doesn't repeat itself) go in; a JSON list of questions, each with a short
 * background line and a category, comes back through structured output. The questions are added
 * as waiting for approval, or approved if Settings says so.
 *
 * The API key comes from the PULSE_QUESTION_ANTHROPIC_API_KEY constant (wp-config.php) or
 * Settings. Requests use server-side fallbacks, so a request the model declines for policy
 * reasons is retried on a fallback model instead of failing.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Claude;

use PulseQuestion\Questions;
use PulseQuestion\Settings;
use PulseQuestion\Vendor\Anthropic\Core\Exceptions\APIConnectionException;
use PulseQuestion\Vendor\Anthropic\Core\Exceptions\APIStatusException;
use PulseQuestion\Vendor\Anthropic\Core\Exceptions\AuthenticationException;
use PulseQuestion\Vendor\Anthropic\Core\Exceptions\InternalServerException;
use PulseQuestion\Vendor\Anthropic\Core\Exceptions\RateLimitException;

final class Generator {

	public const SOURCE    = 'claude';
	public const LOG       = 'pulse_question_claude_log';
	private const LOG_SIZE = 30;

	/** How many recent questions Claude sees, to avoid repeats. */
	private const RECENT = 300;

	/** Default guidance: big variety, politics only on rare occasions. */
	public const DEFAULT_GUIDANCE = "Big variety. Mix personal memories, hypotheticals, values and character, relationships, work and money, creativity, childhood, the future, nature and science, food and travel, culture, small everyday pleasures, playful and funny questions, and the occasional deep philosophical one.\nThought-provoking but answerable by anyone, in a sentence or a paragraph, without special knowledge.\nWarm and curious, never preachy. Nothing that could embarrass, shame or upset people who answer in public.\nStay away from politics, parties, elections and divisive current events. On rare occasions (no more than about one question in fifty), a civic or political question is fine if it invites personal reflection rather than taking sides.";

	public static function apiKey(): string {
		if ( defined( 'PULSE_QUESTION_ANTHROPIC_API_KEY' ) && is_string( PULSE_QUESTION_ANTHROPIC_API_KEY ) && '' !== PULSE_QUESTION_ANTHROPIC_API_KEY ) {
			return PULSE_QUESTION_ANTHROPIC_API_KEY;
		}
		return (string) Settings::get( 'claude_api_key' );
	}

	public static function keyFromConstant(): bool {
		return defined( 'PULSE_QUESTION_ANTHROPIC_API_KEY' ) && '' !== (string) PULSE_QUESTION_ANTHROPIC_API_KEY;
	}

	public static function isConfigured(): bool {
		return '' !== self::apiKey();
	}

	/**
	 * Write $count questions and add them.
	 *
	 * @param string $theme   Optional theme for this batch ("winter", "food and family").
	 * @param string $trigger manual or auto, for the log.
	 * @return array{added: list<int>, skipped: int, model: string}|\WP_Error
	 */
	public static function generate( int $count, string $theme = '', string $trigger = 'manual' ): array|\WP_Error {
		$count = max( 1, min( 30, $count ) );
		if ( ! self::isConfigured() ) {
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_no_key', __( 'Add an Anthropic API key in Questions → Settings first.', 'pulse-question' ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a batch can take a minute or two.
		}

		$model = (string) Settings::get( 'claude_model' );
		try {
			$client  = Api::client( 180.0, 2 );
			$message = $client->beta->messages->create(
				model: $model,
				maxTokens: 16000,
				system: self::systemPrompt(),
				messages: array(
					array(
						'role'    => 'user',
						'content' => self::userPrompt( $count, $theme ),
					),
				),
				outputConfig: array(
					'effort' => (string) Settings::get( 'claude_effort' ),
					'format' => array(
						'type'   => 'json_schema',
						'schema' => self::schema(),
					),
				),
				fallbacks: 'default',
				betas: array( 'server-side-fallback-2026-07-01' ),
			);
		} catch ( AuthenticationException ) {
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_auth', __( 'The Anthropic API key was refused. Check it in Questions → Settings.', 'pulse-question' ) ) );
		} catch ( RateLimitException ) {
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_rate', __( 'The Anthropic API is busy (rate limit). Try again in a few minutes.', 'pulse-question' ) ) );
		} catch ( InternalServerException ) {
			// 5xx, including 529 overloaded: the SDK has already retried.
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_busy', __( 'Anthropic\'s servers are busy or having trouble right now. Try again in a few minutes.', 'pulse-question' ) ) );
		} catch ( APIStatusException $e ) {
			$detail = $e->getAPIMessage();
			$detail = null !== $detail && '' !== $detail ? $detail : sprintf( 'HTTP %d', (int) $e->status );
			/* translators: %s: error from the Anthropic API. */
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_api', sprintf( __( 'The Anthropic API returned an error: %s', 'pulse-question' ), $detail ) ) );
		} catch ( APIConnectionException $e ) {
			/* translators: %s: error message. */
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_connect', sprintf( __( 'Couldn\'t reach the Anthropic API: %s', 'pulse-question' ), $e->getMessage() ) ) );
		} catch ( \Throwable $e ) {
			/* translators: %s: error message. */
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_error', sprintf( __( 'Writing questions failed: %s', 'pulse-question' ), $e->getMessage() ) ) );
		}

		if ( 'refusal' === $message->stopReason ) {
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_refusal', __( 'Claude declined this request. Try a different theme or adjust the guidance in Settings.', 'pulse-question' ) ) );
		}

		$json = '';
		$used = $model;
		foreach ( $message->content as $block ) {
			if ( 'text' === $block->type ) {
				$json .= $block->text;
			} elseif ( 'fallback' === $block->type ) {
				$used = (string) ( $block->to->model ?? $used );
			}
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['questions'] ) || ! is_array( $data['questions'] ) ) {
			$why = 'max_tokens' === $message->stopReason ? __( 'The answer was cut off.', 'pulse-question' ) : __( 'The answer wasn\'t in the expected format.', 'pulse-question' );
			/* translators: %s: reason. */
			return self::fail( $trigger, new \WP_Error( 'pulse_question_claude_format', sprintf( __( 'Writing questions failed: %s', 'pulse-question' ), $why ) ) );
		}

		$result = self::save( $data['questions'] );
		self::log(
			array(
				'trigger' => $trigger,
				'added'   => count( $result['added'] ),
				'skipped' => $result['skipped'],
				'model'   => $used,
				'input'   => (int) ( $message->usage->inputTokens ?? 0 ),
				'output'  => (int) ( $message->usage->outputTokens ?? 0 ),
				'error'   => '',
			)
		);
		/**
		 * Fires after Claude has written a batch of questions.
		 *
		 * @param list<int> $ids     The questions added.
		 * @param string    $trigger manual or auto.
		 */
		do_action( 'pulse_question_claude_wrote', $result['added'], $trigger );
		return $result + array( 'model' => $used );
	}

	/**
	 * Add the questions Claude wrote, skipping repeats.
	 *
	 * @param array<mixed> $items Decoded questions.
	 * @return array{added: list<int>, skipped: int}
	 */
	public static function save( array $items ): array {
		$seen = array();
		foreach ( self::existingTitles( 0 ) as $title ) {
			$seen[ self::normalize( $title ) ] = true;
		}
		$status  = Settings::get( 'claude_auto_approve' ) ? 'approved' : 'pending';
		$added   = array();
		$skipped = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = trim( (string) ( $item['question'] ?? '' ) );
			$key      = self::normalize( $question );
			if ( '' === $key || isset( $seen[ $key ] ) || mb_strlen( $question ) > 300 ) {
				++$skipped;
				continue;
			}
			$seen[ $key ] = true;
			$id           = Questions::add( $question, $status, self::SOURCE, sanitize_text_field( (string) ( $item['background'] ?? '' ) ) );
			if ( is_wp_error( $id ) ) {
				++$skipped;
				continue;
			}
			$category = trim( sanitize_text_field( (string) ( $item['category'] ?? '' ) ) );
			if ( '' !== $category && mb_strlen( $category ) <= 40 ) {
				wp_set_object_terms( $id, $category, Questions::TAXONOMY );
			}
			$added[] = $id;
		}
		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/** For spotting repeats: lowercase, letters and digits only. */
	public static function normalize( string $text ): string {
		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $text ) );
	}

	public static function systemPrompt(): string {
		$guidance = trim( (string) Settings::get( 'claude_guidance' ) );
		if ( '' === $guidance ) {
			$guidance = self::DEFAULT_GUIDANCE;
		}
		return "You write questions for Pulse Question, a website that asks one thought-provoking question each day. Anyone can read it, and visitors answer in public, so each question should invite a personal, honest answer and be interesting to read other people's answers to.\n\n"
			. "Each question is one sentence ending in a question mark, usually 6 to 20 words, in plain, warm English. Each comes with a background line: one or two short sentences shown under the question that add a nudge, an angle or a little context. They must never answer the question or lecture. Each also gets a short category name (one to three words).\n\n"
			. "Make every question in a batch different in subject, style and length, and different from the questions the site has already asked.\n\n"
			. "Guidance from the site owner:\n" . $guidance;
	}

	public static function userPrompt( int $count, string $theme = '' ): string {
		$categories = get_terms(
			array(
				'taxonomy'   => Questions::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);
		$categories = is_array( $categories ) ? $categories : array();
		$recent     = self::existingTitles( self::RECENT );

		$text = sprintf( 'Write %d new questions.', $count );
		if ( '' !== trim( $theme ) ) {
			$text .= ' Theme for this batch: ' . trim( sanitize_text_field( $theme ) ) . '. Keep variety within the theme.';
		}
		$text .= "\n\n" . ( $categories
			? 'Categories in use (reuse one when it fits; otherwise make a new short one): ' . implode( ', ', $categories ) . '.'
			: 'No categories exist yet: make short, reusable ones.' );
		if ( $recent ) {
			$text .= "\n\nQuestions the site already has. Don't repeat or closely paraphrase any of them:\n- " . implode( "\n- ", $recent );
		}
		return $text;
	}

	/** @return array<string, mixed> */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'questions' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'question'   => array(
								'type'        => 'string',
								'description' => 'The question, one sentence ending in a question mark.',
							),
							'background' => array(
								'type'        => 'string',
								'description' => 'One or two short sentences shown under the question.',
							),
							'category'   => array(
								'type'        => 'string',
								'description' => 'A short category name, one to three words.',
							),
						),
						'required'             => array( 'question', 'background', 'category' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'questions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Titles of the site's questions, newest first (every status but trash).
	 *
	 * @return list<string>
	 */
	public static function existingTitles( int $limit ): array {
		$ids = get_posts(
			array(
				'post_type'        => Questions::POST_TYPE,
				'post_status'      => array( 'publish', Questions::STATUS_APPROVED, 'pending', 'draft' ),
				'numberposts'      => $limit > 0 ? $limit : -1,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		return array_values( array_filter( array_map( static fn ( $id ) => (string) get_the_title( (int) $id ), $ids ) ) );
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function history(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/** @param array<string, mixed> $entry Run details. */
	private static function log( array $entry ): void {
		$log = self::history();
		array_unshift( $log, $entry + array( 'time' => time() ) );
		update_option( self::LOG, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	private static function fail( string $trigger, \WP_Error $error ): \WP_Error {
		self::log(
			array(
				'trigger' => $trigger,
				'added'   => 0,
				'skipped' => 0,
				'model'   => (string) Settings::get( 'claude_model' ),
				'input'   => 0,
				'output'  => 0,
				'error'   => $error->get_error_message(),
			)
		);
		return $error;
	}
}
