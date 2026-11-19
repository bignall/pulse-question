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
		$time = (string) ( $input['switch_time'] ?? '00:00' );
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
			'remove_data'    => empty( $input['remove_data'] ) ? 0 : 1,
		);
	}
}
