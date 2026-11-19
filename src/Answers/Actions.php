<?php
/**
 * Votes and reports from members: a REST API for the page's JavaScript
 * (POST /wp-json/pulse-question/v1/vote and /report, with the logged-in member's REST nonce),
 * and the same actions as plain form posts when JavaScript is off.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Answers;

final class Actions {

	public const NAMESPACE = 'pulse-question/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_pulse_question_vote', array( self::class, 'formVote' ) );
		add_action( 'admin_post_pulse_question_report', array( self::class, 'formReport' ) );
		add_action( 'admin_post_nopriv_pulse_question_vote', array( self::class, 'toLogin' ) );
		add_action( 'admin_post_nopriv_pulse_question_report', array( self::class, 'toLogin' ) );
	}

	public static function routes(): void {
		$member = static fn (): bool => is_user_logged_in();
		register_rest_route(
			self::NAMESPACE,
			'/vote',
			array(
				'methods'             => 'POST',
				'permission_callback' => $member,
				'args'                => array(
					'type' => array(
						'type'     => 'string',
						'enum'     => array( 'question', 'answer' ),
						'required' => true,
					),
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$result = Votes::toggle( (string) $r['type'], (int) $r['id'], get_current_user_id() );
					return is_wp_error( $result ) ? self::error( $result ) : new \WP_REST_Response( $result );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => 'POST',
				'permission_callback' => $member,
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$result = Answers::report( (int) $r['id'], get_current_user_id() );
					return is_wp_error( $result ) ? self::error( $result ) : new \WP_REST_Response( $result );
				},
			)
		);
	}

	private static function error( \WP_Error $e ): \WP_Error {
		$e->add_data( array( 'status' => 400 ) );
		return $e;
	}

	public static function formVote(): void {
		check_admin_referer( 'pulse_question_vote' );
		Votes::toggle( sanitize_key( (string) ( $_POST['type'] ?? '' ) ), absint( $_POST['id'] ?? 0 ), get_current_user_id() );
		self::back();
	}

	public static function formReport(): void {
		check_admin_referer( 'pulse_question_report' );
		Answers::report( absint( $_POST['id'] ?? 0 ), get_current_user_id() );
		self::back( 'reported' );
	}

	public static function toLogin(): void {
		wp_safe_redirect( wp_login_url( wp_get_referer() ?: home_url() ) );
		exit;
	}

	private static function back( string $flag = '' ): void {
		$to = wp_get_referer() ?: home_url();
		if ( '' !== $flag ) {
			$to = add_query_arg( 'pulse_question_' . $flag, 1, $to );
		}
		wp_safe_redirect( $to . '#pq-answers' );
		exit;
	}
}
