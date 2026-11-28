<?php
/**
 * Sends the daily email as a FluentCRM campaign, on this site or on another site (through
 * FluentCRM's REST API with the application password from Settings → Members). FluentCRM then
 * does the sending, the unsubscribe links, the footer and the open and click reports.
 *
 * Steps, the same ones FluentCRM's own campaign screen takes: create the campaign, set its
 * subject and body, choose the recipients (the daily email lists, subscribed contacts only),
 * then send. If a step fails, the campaign stays a draft in FluentCRM and the next try picks
 * it up again instead of making another.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Email;

use PulseQuestion\Members\MailingList;
use PulseQuestion\Settings;

final class FluentCampaign {

	/**
	 * Send one campaign.
	 *
	 * @param array{subject: string, preheader: string, body: string} $content  The email.
	 * @param string                                                   $title    Campaign title (FluentCRM wants it unique).
	 * @param int                                                      $existing A campaign made by an earlier try today, or 0.
	 * @param callable(int): void                                      $made     Called as soon as the campaign exists, with its id.
	 * @return array{ok: bool, campaign: int, recipients: int, error: string}
	 */
	public static function send( array $content, string $title, int $existing, callable $made ): array {
		$result = static fn ( bool $ok, int $campaign, int $recipients, string $error = '' ): array => array(
			'ok'         => $ok,
			'campaign'   => $campaign,
			'recipients' => $recipients,
			'error'      => $error,
		);
		$lists = self::listIds();
		if ( ! $lists ) {
			return $result( false, $existing, 0, __( 'No daily email list is set. Add its id under Settings → Members → List ids.', 'pulse-question' ) );
		}

		// 1. The campaign: an earlier try's draft, or a new one.
		$id = 0;
		if ( $existing > 0 ) {
			$r = self::request( 'GET', '/campaigns/' . $existing );
			if ( is_string( $r ) ) {
				return $result( false, $existing, 0, $r );
			}
			if ( 200 === $r['code'] && is_array( $r['body']['campaign'] ?? null ) ) {
				if ( 'draft' !== ( $r['body']['campaign']['status'] ?? '' ) ) {
					// Sent (or being sent) by an earlier try whose answer got lost.
					return $result( true, $existing, (int) ( $r['body']['campaign']['recipients_count'] ?? 0 ) );
				}
				$id = $existing;
			}
		}
		if ( 0 === $id ) {
			$r = self::request( 'POST', '/campaigns', array( 'title' => $title ) );
			if ( ! is_string( $r ) && 422 === $r['code'] ) {
				// A campaign with this title exists (made by hand, or deleted from our records).
				$r = self::request( 'POST', '/campaigns', array( 'title' => $title . ' ' . wp_date( 'H:i:s' ) ) );
			}
			if ( is_string( $r ) ) {
				return $result( false, 0, 0, $r );
			}
			$id = (int) ( $r['body']['id'] ?? $r['body']['campaign']['id'] ?? 0 );
			if ( $id <= 0 ) {
				return $result( false, 0, 0, self::problem( $r, __( 'FluentCRM didn\'t create the campaign.', 'pulse-question' ) ) );
			}
			$made( $id );
		}

		// 2. Subject and body.
		$r = self::request(
			'PUT',
			'/campaigns/' . $id,
			array(
				'title'            => $title,
				'email_subject'    => $content['subject'],
				'email_pre_header' => $content['preheader'],
				'email_body'       => $content['body'],
				'design_template'  => 'simple',
				'settings'         => array( 'mailer_settings' => self::sender() ),
			)
		);
		if ( ! is_string( $r ) && 422 === $r['code'] && str_contains( wp_json_encode( $r['body'] ), 'title' ) ) {
			// The title is taken by another campaign; keep the one the campaign already has.
			$current = self::request( 'GET', '/campaigns/' . $id );
			$keep    = is_string( $current ) ? '' : (string) ( $current['body']['campaign']['title'] ?? '' );
			$r       = self::request(
				'PUT',
				'/campaigns/' . $id,
				array(
					'title'            => '' !== $keep ? $keep : $title . ' ' . wp_date( 'H:i:s' ),
					'email_subject'    => $content['subject'],
					'email_pre_header' => $content['preheader'],
					'email_body'       => $content['body'],
					'design_template'  => 'simple',
					'settings'         => array( 'mailer_settings' => self::sender() ),
				)
			);
		}
		if ( is_string( $r ) || 200 !== $r['code'] ) {
			return $result( false, $id, 0, is_string( $r ) ? $r : self::problem( $r, __( 'FluentCRM didn\'t save the email.', 'pulse-question' ) ) );
		}

		// 3. Recipients: everyone subscribed to the daily email lists.
		$r = self::request(
			'POST',
			'/campaigns/' . $id . '/draft-recipients',
			array(
				'subscribers'    => array_map(
					static fn ( int $list ): array => array(
						'list' => (string) $list,
						'tag'  => 'all',
					),
					$lists
				),
				'sending_filter' => 'list_tag',
			)
		);
		if ( is_string( $r ) ) {
			return $result( false, $id, 0, $r );
		}
		$count = (int) ( $r['body']['count'] ?? 0 );
		$no_one = ( 200 === $r['code'] && $count <= 0 ) || str_contains( (string) ( $r['body']['message'] ?? '' ), 'No contacts' );
		if ( $no_one ) {
			return $result( false, $id, 0, __( 'Nobody subscribed is on the daily email list yet, so there was no one to send to.', 'pulse-question' ) );
		}
		if ( 200 !== $r['code'] ) {
			return $result( false, $id, 0, self::problem( $r, __( 'FluentCRM didn\'t accept the recipients.', 'pulse-question' ) ) );
		}

		// 4. Send now.
		$r = self::request( 'POST', '/campaigns/' . $id . '/schedule', array() );
		if ( is_string( $r ) || 200 !== $r['code'] ) {
			return $result( false, $id, $count, is_string( $r ) ? $r : self::problem( $r, __( 'FluentCRM didn\'t start sending.', 'pulse-question' ) ) );
		}
		return $result( true, $id, $count );
	}

	/**
	 * The campaign's sender, from Settings → Daily email → FluentCRM sender.
	 *
	 * @return array{from_name: string, from_email: string, reply_to_name: string, reply_to_email: string, is_custom: string}
	 */
	public static function sender(): array {
		return self::mailerSettings( (string) Settings::get( 'crm_from_name' ), (string) Settings::get( 'crm_from_address' ), (string) Settings::get( 'crm_reply_to' ) );
	}

	/**
	 * FluentCRM's per-campaign sender. It only uses a custom sender when the address is given too
	 * (a name alone falls back to the mail plugin's sender), and a custom sender drops FluentCRM's
	 * default reply-to, so both go with the name. Without a name and address, FluentCRM's own
	 * sender settings are used.
	 *
	 * @return array{from_name: string, from_email: string, reply_to_name: string, reply_to_email: string, is_custom: string}
	 */
	public static function mailerSettings( string $name, string $address, string $reply_to ): array {
		$name     = trim( $name );
		$address  = trim( $address );
		$reply_to = trim( $reply_to );
		if ( '' === $name || '' === $address ) {
			return array(
				'from_name'      => '',
				'from_email'     => '',
				'reply_to_name'  => '',
				'reply_to_email' => '',
				'is_custom'      => 'no',
			);
		}
		return array(
			'from_name'      => $name,
			'from_email'     => $address,
			'reply_to_name'  => '' !== $reply_to ? $name : '',
			'reply_to_email' => $reply_to,
			'is_custom'      => 'yes',
		);
	}

	/** @return list<int> */
	public static function listIds(): array {
		return array_values( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) Settings::get( 'list_ids' ) ) ?: array() ) ) );
	}

	/**
	 * Explain an unexpected answer.
	 *
	 * @param array{code: int, body: mixed} $r Response.
	 */
	private static function problem( array $r, string $fallback ): string {
		$code    = is_array( $r['body'] ) ? (string) ( $r['body']['code'] ?? '' ) : '';
		$message = is_array( $r['body'] ) ? (string) ( $r['body']['message'] ?? '' ) : '';
		if ( 'rest_no_route' === $code ) {
			return __( 'FluentCRM isn\'t active on the mailing list site.', 'pulse-question' );
		}
		if ( 401 === $r['code'] || 403 === $r['code'] ) {
			return __( 'FluentCRM refused: the user in Settings → Members needs the "Write and send emails" permission (FluentCRM → Settings → Managers), or use an administrator. Test connection, under Members, checks the login.', 'pulse-question' );
		}
		/* translators: 1: what failed, 2: HTTP status, 3: FluentCRM's message. */
		return trim( sprintf( __( '%1$s (HTTP %2$d) %3$s', 'pulse-question' ), $fallback, $r['code'], $message ) );
	}

	/**
	 * One FluentCRM REST request, on this site or the other one.
	 *
	 * @param array<string, mixed>|null $body JSON body.
	 * @return array{code: int, body: mixed}|string The answer, or what went wrong.
	 */
	public static function request( string $method, string $route, ?array $body = null ): array|string {
		$route = '/fluent-crm/v2' . $route;
		if ( 'fluentcrm' === MailingList::provider() ) {
			return self::local( $method, $route, $body );
		}
		$url  = untrailingslashit( (string) Settings::get( 'list_remote_url' ) );
		$user = (string) Settings::get( 'list_remote_user' );
		$pass = (string) Settings::get( 'list_remote_password' );
		if ( '' === $url || '' === $user || '' === $pass ) {
			return __( 'The mailing list site\'s address, user name or application password is missing in Settings → Members.', 'pulse-question' );
		}
		$response = wp_remote_request(
			add_query_arg( 'rest_route', $route, $url . '/' ),
			array(
				'method'      => $method,
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => null === $body ? null : (string) wp_json_encode( (object) $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error. */
			return sprintf( __( 'Couldn\'t reach the mailing list site: %s', 'pulse-question' ), $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			return __( 'The mailing list site redirected the request, so the login was lost. Test connection, under Settings → Members, shows the address to use.', 'pulse-question' );
		}
		return array(
			'code' => $code,
			'body' => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
		);
	}

	/**
	 * FluentCRM on this site: the same REST routes, called inside WordPress as an administrator
	 * (the daily email runs from WP-Cron, where nobody is logged in).
	 *
	 * @param array<string, mixed>|null $body JSON body.
	 * @return array{code: int, body: mixed}|string
	 */
	private static function local( string $method, string $route, ?array $body ): array|string {
		if ( ! MailingList::localAvailable() ) {
			return __( 'FluentCRM isn\'t active on this site.', 'pulse-question' );
		}
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);
		if ( ! $admins ) {
			return __( 'No administrator was found to send the campaign as.', 'pulse-question' );
		}
		$before = get_current_user_id();
		wp_set_current_user( (int) $admins[0] );
		try {
			$request = new \WP_REST_Request( $method, $route );
			if ( null !== $body ) {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( (object) $body ) );
			}
			$response = rest_do_request( $request );
			$server   = rest_get_server();
			$data     = $server->response_to_data( $response, false );
			return array(
				'code' => (int) $response->get_status(),
				'body' => json_decode( (string) wp_json_encode( $data ), true ),
			);
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		} finally {
			wp_set_current_user( $before );
		}
	}
}
