<?php
/**
 * Adds members to the mailing list: FluentCRM on this site, FluentCRM on another site (its REST
 * API, with an application password), or nothing (hook pulse_question_member_joined to use
 * another service).
 *
 * New contacts are added as "pending" by default, so FluentCRM sends its double opt-in email and
 * only confirmed addresses get mail. A subscription that can't be sent (the other site is down)
 * is retried on the hourly check.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Members;

use PulseQuestion\Daily;
use PulseQuestion\Settings;

final class MailingList {

	/** On the user while a subscription still has to be sent. */
	public const META_PENDING = '_pq_list_pending';
	public const LOG          = 'pq_list_log';

	public static function init(): void {
		add_action( Daily::CRON_HOOK, array( self::class, 'retry' ), 40 );
	}

	public static function provider(): string {
		$p = (string) Settings::get( 'list_provider' );
		return in_array( $p, array( 'none', 'fluentcrm', 'fluentcrm_remote' ), true ) ? $p : 'none';
	}

	public static function localAvailable(): bool {
		return function_exists( 'FluentCrmApi' );
	}

	/**
	 * @return list<int> List ids from a "3, 7" setting.
	 */
	private static function ids( string $setting ): array {
		return array_values( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) Settings::get( $setting ) ) ?: array() ) ) );
	}

	/** @return array<string, mixed> */
	public static function contact( int $user_id, bool $marketing ): array {
		$user  = get_userdata( $user_id );
		$lists = self::ids( 'list_ids' );
		if ( $marketing ) {
			$lists = array_values( array_unique( array_merge( $lists, self::ids( 'marketing_list_ids' ) ) ) );
		}
		return array(
			'email'      => $user ? $user->user_email : '',
			'first_name' => $user ? (string) $user->first_name : '',
			'last_name'  => $user ? (string) $user->last_name : '',
			'status'     => Settings::get( 'list_double_optin' ) ? 'pending' : 'subscribed',
			'lists'      => $lists,
			'tags'       => self::ids( 'list_tag_ids' ),
		);
	}

	public static function subscribe( int $user_id, bool $marketing ): bool {
		$provider = self::provider();
		if ( 'none' === $provider ) {
			return true;
		}
		$contact = self::contact( $user_id, $marketing );
		if ( '' === $contact['email'] ) {
			return false;
		}
		$result = 'fluentcrm' === $provider ? self::local( $contact ) : self::remote( $contact );
		self::log( $user_id, $result );
		if ( true === $result ) {
			delete_user_meta( $user_id, self::META_PENDING );
			return true;
		}
		update_user_meta( $user_id, self::META_PENDING, $marketing ? 'marketing' : 'list' );
		return false;
	}

	/**
	 * FluentCRM on this site.
	 *
	 * @param array<string, mixed> $contact Contact.
	 */
	private static function local( array $contact ): true|string {
		if ( ! self::localAvailable() ) {
			return __( 'FluentCRM isn\'t active on this site.', 'pulse-question' );
		}
		try {
			$subscriber = FluentCrmApi( 'contacts' )->createOrUpdate( $contact );
			if ( $subscriber && 'pending' === ( $subscriber->status ?? '' ) && method_exists( $subscriber, 'sendDoubleOptinEmail' ) ) {
				$subscriber->sendDoubleOptinEmail();
			}
			return $subscriber ? true : __( 'FluentCRM didn\'t add the contact.', 'pulse-question' );
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * FluentCRM on another site, through its REST API.
	 *
	 * @param array<string, mixed> $contact Contact.
	 */
	private static function remote( array $contact ): true|string {
		$url  = untrailingslashit( (string) Settings::get( 'list_remote_url' ) );
		$user = (string) Settings::get( 'list_remote_user' );
		$pass = (string) Settings::get( 'list_remote_password' );
		if ( '' === $url || '' === $user || '' === $pass ) {
			return __( 'The other site\'s address, user name or application password is missing in Settings.', 'pulse-question' );
		}
		// ?rest_route= works whatever the other site's permalink settings are.
		$response = wp_remote_post(
			add_query_arg( 'rest_route', '/fluent-crm/v2/subscribers', $url . '/' ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode( $contact + array( '__force_update' => 'yes' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		// Only a FluentCRM answer counts (a 200 page from a site without FluentCRM doesn't).
		if ( $code >= 200 && $code < 300 && is_array( $body ) && ( isset( $body['contact'] ) || isset( $body['subscriber'] ) || isset( $body['id'] ) ) ) {
			return true;
		}
		if ( $code >= 200 && $code < 300 ) {
			return __( 'The other site answered, but not like FluentCRM. Check the address and that FluentCRM is active there.', 'pulse-question' );
		}
		/* translators: 1: HTTP status code, 2: message. */
		return sprintf( __( 'The other site answered HTTP %1$d: %2$s', 'pulse-question' ), $code, is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : '' );
	}

	/** Send subscriptions that failed before. */
	public static function retry(): void {
		if ( 'none' === self::provider() ) {
			return;
		}
		$users = get_users(
			array(
				'meta_key' => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'   => 20,
				'fields'   => 'ids',
			)
		);
		foreach ( $users as $user_id ) {
			self::subscribe( (int) $user_id, 'marketing' === get_user_meta( (int) $user_id, self::META_PENDING, true ) );
		}
	}

	/** @return list<array{time: int, user: int, ok: bool, error: string}> */
	public static function history(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	private static function log( int $user_id, true|string $result ): void {
		$log = self::history();
		array_unshift(
			$log,
			array(
				'time'  => time(),
				'user'  => $user_id,
				'ok'    => true === $result,
				'error' => true === $result ? '' : $result,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 20 ), false );
	}
}
