<?php
/**
 * Plugin settings, in one option (WP Foundation's Settings).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

use PulseQuestion\Vendor\Bignall\WPFoundation\Settings\Settings as Store;

final class Settings {

	public const OPTION = 'pq_settings';

	private static ?Store $store = null;

	public static function store(): Store {
		if ( null === self::$store ) {
			self::$store = new Store( self::OPTION, self::defaults(), array( self::class, 'sanitize' ) );
		}
		return self::$store;
	}

	/**
	 * Plain strings, not translated: this runs before translations can load.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'switch_time'     => '00:00',
			'approve_mine'    => 1,
			'per_page'        => 12,
			'label'           => 'Question of the day',
			'low_queue_days'  => 7,
			'todo_tasks'      => 1,
			'load_styles'     => 1,
			'claude_api_key'      => '',
			'claude_model'        => 'claude-opus-5-5',
			'claude_effort'       => 'medium',
			'claude_guidance'     => Claude\Generator::DEFAULT_GUIDANCE,
			'claude_auto'         => 0,
			'claude_target_days'  => 14,
			'claude_batch'        => 10,
			'claude_auto_approve' => 0,
			'answers_open'          => 1,
			'answer_max_length'     => 1500,
			'answer_emails'         => 0,
			'report_threshold'      => 3,
			'moderation'            => 'claude',
			'moderation_model'      => 'claude-opus-5-5',
			'moderation_effort'     => 'low',
			'moderation_guidelines' => Answers\Moderator::DEFAULT_GUIDELINES,
			'list_provider'         => 'none',
			'list_ids'              => '',
			'marketing_list_ids'    => '',
			'list_tag_ids'          => '',
			'list_double_optin'     => 1,
			'join_prechecked'       => 1,
			'list_remote_url'       => '',
			'list_remote_user'      => '',
			'list_remote_password'  => '',
			'list_label'            => 'Send me the question of the day by email',
			'marketing_label'       => 'Also send me occasional news and offers',
			'email_enabled'         => 0,
			'email_from'            => '',
			'email_subject'         => '{question}',
			'email_intro'           => '',
			'email_button'          => 'Answer today\'s question',
			'email_top_answers'     => 3,
			'social_webhook_url'    => '',
			'social_from'           => '',
			'social_text'           => "{question}\n\nShare your answer: {url}\n\n{hashtags}",
			'social_hashtags'       => 'QuestionOfTheDay',
			'share_buttons'         => 1,
			'share_tags'            => 1,
			'remove_data'     => 0,
		);
	}

	public static function get( string $key ): mixed {
		return self::store()->get( $key );
	}

	/**
	 * @param array<string, mixed> $input   Submitted settings.
	 * @param Store                $current Current settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, Store $current ): array {
		$time    = (string) ( $input['switch_time'] ?? '00:00' );
		$email   = empty( $input['email_enabled'] ) ? 0 : 1;
		$webhook = esc_url_raw( trim( (string) ( $input['social_webhook_url'] ?? '' ) ), array( 'https', 'http' ) );
		// Automatic sending starts with the next question: nobody gets a surprise email mid-day.
		$next = static fn (): string => ( new \DateTimeImmutable( Daily::day() . ' 12:00', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' );
		return array(
			'switch_time'    => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '00:00',
			'approve_mine'   => empty( $input['approve_mine'] ) ? 0 : 1,
			'per_page'       => max( 3, min( 60, (int) ( $input['per_page'] ?? 12 ) ) ),
			'label'          => sanitize_text_field( (string) ( $input['label'] ?? '' ) ),
			'low_queue_days' => max( 0, min( 60, (int) ( $input['low_queue_days'] ?? 7 ) ) ),
			'todo_tasks'     => empty( $input['todo_tasks'] ) ? 0 : 1,
			'load_styles'    => empty( $input['load_styles'] ) ? 0 : 1,
			// A blank key field keeps the saved key; tick "remove" to clear it.
			'claude_api_key'      => ! empty( $input['claude_api_key_clear'] ) ? '' : ( '' !== trim( (string) ( $input['claude_api_key'] ?? '' ) ) ? trim( sanitize_text_field( (string) $input['claude_api_key'] ) ) : (string) $current->get( 'claude_api_key' ) ),
			'claude_model'        => preg_match( '/^[a-z0-9][a-z0-9.\-]{2,80}$/', (string) ( $input['claude_model'] ?? '' ) ) ? (string) $input['claude_model'] : 'claude-opus-5-5',
			'claude_effort'       => in_array( $input['claude_effort'] ?? '', array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ? (string) $input['claude_effort'] : 'medium',
			'claude_guidance'     => sanitize_textarea_field( (string) ( $input['claude_guidance'] ?? '' ) ),
			'claude_auto'         => empty( $input['claude_auto'] ) ? 0 : 1,
			'claude_target_days'  => max( 1, min( 90, (int) ( $input['claude_target_days'] ?? 14 ) ) ),
			'claude_batch'        => max( 1, min( 30, (int) ( $input['claude_batch'] ?? 10 ) ) ),
			'claude_auto_approve' => empty( $input['claude_auto_approve'] ) ? 0 : 1,
			'answers_open'          => empty( $input['answers_open'] ) ? 0 : 1,
			'answer_max_length'     => max( 0, min( 10000, (int) ( $input['answer_max_length'] ?? 1500 ) ) ),
			'answer_emails'         => empty( $input['answer_emails'] ) ? 0 : 1,
			'report_threshold'      => max( 0, min( 50, (int) ( $input['report_threshold'] ?? 3 ) ) ),
			'moderation'            => in_array( $input['moderation'] ?? '', array( 'claude', 'hold', 'wordpress' ), true ) ? (string) $input['moderation'] : 'claude',
			'moderation_model'      => preg_match( '/^[a-z0-9][a-z0-9.\-]{2,80}$/', (string) ( $input['moderation_model'] ?? '' ) ) ? (string) $input['moderation_model'] : 'claude-opus-5-5',
			'moderation_effort'     => in_array( $input['moderation_effort'] ?? '', array( 'low', 'medium', 'high' ), true ) ? (string) $input['moderation_effort'] : 'low',
			'moderation_guidelines' => sanitize_textarea_field( (string) ( $input['moderation_guidelines'] ?? '' ) ),
			'list_provider'         => in_array( $input['list_provider'] ?? '', array( 'none', 'fluentcrm', 'fluentcrm_remote' ), true ) ? (string) $input['list_provider'] : 'none',
			'list_ids'              => preg_replace( '/[^0-9, ]/', '', (string) ( $input['list_ids'] ?? '' ) ),
			'marketing_list_ids'    => preg_replace( '/[^0-9, ]/', '', (string) ( $input['marketing_list_ids'] ?? '' ) ),
			'list_tag_ids'          => preg_replace( '/[^0-9, ]/', '', (string) ( $input['list_tag_ids'] ?? '' ) ),
			'list_double_optin'     => empty( $input['list_double_optin'] ) ? 0 : 1,
			'join_prechecked'       => empty( $input['join_prechecked'] ) ? 0 : 1,
			'list_remote_url'       => untrailingslashit( esc_url_raw( trim( (string) ( $input['list_remote_url'] ?? '' ) ), array( 'https', 'http' ) ) ),
			'list_remote_user'      => sanitize_user( (string) ( $input['list_remote_user'] ?? '' ) ),
			// A blank password field keeps the saved one.
			'list_remote_password'  => '' !== trim( (string) ( $input['list_remote_password'] ?? '' ) ) ? preg_replace( '/\s+/', '', (string) $input['list_remote_password'] ) : (string) $current->get( 'list_remote_password' ),
			'list_label'            => sanitize_text_field( (string) ( $input['list_label'] ?? '' ) ),
			'marketing_label'       => sanitize_text_field( (string) ( $input['marketing_label'] ?? '' ) ),
			'email_enabled'         => $email,
			'email_from'            => ! $email ? '' : ( $current->get( 'email_enabled' ) && '' !== (string) $current->get( 'email_from' ) ? (string) $current->get( 'email_from' ) : $next() ),
			'email_subject'         => sanitize_text_field( (string) ( $input['email_subject'] ?? '{question}' ) ),
			'email_intro'           => sanitize_textarea_field( (string) ( $input['email_intro'] ?? '' ) ),
			'email_button'          => '' !== trim( (string) ( $input['email_button'] ?? '' ) ) ? sanitize_text_field( (string) $input['email_button'] ) : 'Answer today\'s question',
			'email_top_answers'     => max( 0, min( 10, (int) ( $input['email_top_answers'] ?? 3 ) ) ),
			'social_webhook_url'    => $webhook,
			'social_from'           => '' === $webhook ? '' : ( '' !== (string) $current->get( 'social_webhook_url' ) && '' !== (string) $current->get( 'social_from' ) ? (string) $current->get( 'social_from' ) : $next() ),
			'social_text'           => sanitize_textarea_field( (string) ( $input['social_text'] ?? '' ) ),
			'social_hashtags'       => sanitize_text_field( (string) ( $input['social_hashtags'] ?? '' ) ),
			'share_buttons'         => empty( $input['share_buttons'] ) ? 0 : 1,
			'share_tags'            => empty( $input['share_tags'] ) ? 0 : 1,
			'remove_data'    => empty( $input['remove_data'] ) ? 0 : 1,
		);
	}
}
