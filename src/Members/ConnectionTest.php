<?php
/**
 * "Test connection" for the mailing list (Settings → Mailing list). Checks the saved settings one
 * step at a time and says in plain words which step failed and what to do:
 *
 *   FluentCRM on another site: can we reach it, does a redirect get in the way, is the login
 *   seen and accepted, can that user use FluentCRM, do the list ids exist there.
 *   FluentCRM on this site: is it active, do the list ids exist.
 *
 * Nothing is added to the list by the test.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Members;

use PulseQuestion\Settings;

final class ConnectionTest {

	/**
	 * @return list<array{ok: bool|null, text: string}> Steps in order; ok null is a warning.
	 */
	public static function run(): array {
		return match ( MailingList::provider() ) {
			'fluentcrm'        => self::local(),
			'fluentcrm_remote' => self::remote(),
			default            => array( self::step( false, __( 'No mailing list is connected. Choose FluentCRM on this site or on another site, save, then test.', 'pulse-question' ) ) ),
		};
	}

	/** @return list<array{ok: bool|null, text: string}> */
	private static function local(): array {
		if ( ! MailingList::localAvailable() ) {
			return array( self::step( false, __( 'FluentCRM isn\'t active on this site. Activate it, or choose FluentCRM on another site.', 'pulse-question' ) ) );
		}
		$steps = array( self::step( true, __( 'FluentCRM is active on this site.', 'pulse-question' ) ) );
		$lists = null;
		try {
			$api = FluentCrmApi( 'lists' );
			if ( is_object( $api ) && method_exists( $api, 'all' ) ) {
				$lists = array();
				foreach ( $api->all() as $list ) {
					$lists[ (int) $list->id ] = (string) $list->title;
				}
			}
		} catch ( \Throwable $e ) {
			$lists = null;
		}
		return array_merge( $steps, self::checkIds( $lists ) );
	}

	/** @return list<array{ok: bool|null, text: string}> */
	private static function remote(): array {
		$url  = untrailingslashit( trim( (string) Settings::get( 'list_remote_url' ) ) );
		$user = (string) Settings::get( 'list_remote_user' );
		$pass = (string) Settings::get( 'list_remote_password' );
		if ( '' === $url || '' === $user || '' === $pass ) {
			return array( self::step( false, __( 'Fill in the other site\'s address, user name and application password, save, then test.', 'pulse-question' ) ) );
		}
		$steps = array();
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			$steps[] = self::step( null, __( 'The address starts with http://. WordPress only accepts application passwords over https://, so use the https:// address.', 'pulse-question' ) );
		}

		// 1. Who are we on that site?
		$me = self::get( $url, '/wp/v2/users/me', $user, $pass );
		if ( is_string( $me ) ) {
			$steps[] = self::step( false, $me );
			return $steps;
		}
		if ( 200 === $me['code'] && isset( $me['body']['id'] ) ) {
			/* translators: 1: site, 2: user's display name. */
			$steps[] = self::step( true, sprintf( __( 'Reached %1$s and logged in as %2$s.', 'pulse-question' ), $host, (string) ( $me['body']['name'] ?? $user ) ) );
		} else {
			$steps[] = self::step( false, self::loginProblem( $me, $url, $user, $pass ) );
			return $steps;
		}

		// 2. Can that user use FluentCRM?
		$lists = self::get( $url, '/fluent-crm/v2/lists', $user, $pass );
		if ( is_string( $lists ) ) {
			$steps[] = self::step( false, $lists );
			return $steps;
		}
		$code = (string) ( $lists['body']['code'] ?? '' );
		if ( 'rest_no_route' === $code ) {
			$steps[] = self::step( false, __( 'FluentCRM isn\'t active on that site (its REST API isn\'t there).', 'pulse-question' ) );
			return $steps;
		}
		if ( in_array( $lists['code'], array( 401, 403 ), true ) ) {
			/* translators: %s: user name. */
			$steps[] = self::step( false, sprintf( __( 'Logged in, but %s isn\'t allowed to use FluentCRM there. Use an administrator, or under FluentCRM → Settings → Managers give the user "Add, update and import contacts" and "Create and update tags, lists, companies and segments" (and "Write and send emails" for the daily email).', 'pulse-question' ), $user ) );
			return $steps;
		}
		if ( 200 !== $lists['code'] || ! is_array( $lists['body'] ) ) {
			/* translators: 1: HTTP status, 2: message. */
			$steps[] = self::step( false, sprintf( __( 'FluentCRM answered HTTP %1$d: %2$s', 'pulse-question' ), $lists['code'], (string) ( $lists['body']['message'] ?? '' ) ) );
			return $steps;
		}
		$steps[] = self::step( true, __( 'FluentCRM is active there and this user can use it.', 'pulse-question' ) );

		// 3. Do the list ids exist?
		$rows = $lists['body']['lists'] ?? $lists['body'];
		if ( is_array( $rows ) && isset( $rows['data'] ) && is_array( $rows['data'] ) ) {
			$rows = $rows['data']; // Paginated answer.
		}
		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$found[ (int) $row['id'] ] = (string) ( $row['title'] ?? '' );
			}
		}
		return array_merge( $steps, self::checkIds( $found ) );
	}

	/**
	 * Explain a failed users/me request.
	 *
	 * @param array{code: int, body: mixed} $r Response.
	 */
	private static function loginProblem( array $r, string $url, string $user, string $pass ): string {
		$code = is_array( $r['body'] ) ? (string) ( $r['body']['code'] ?? '' ) : '';
		switch ( $code ) {
			case 'incorrect_password':
				return __( 'The site refused the application password. Make a new one on that site under Users → Profile → Application Passwords and paste it here. Your normal login password won\'t work.', 'pulse-question' );
			case 'invalid_username':
			case 'invalid_email':
				/* translators: %s: user name. */
				return sprintf( __( 'That site has no user called %s. Use the user name of the account that made the application password.', 'pulse-question' ), $user );
			case 'rest_not_logged_in':
			case 'rest_forbidden':
				// WordPress gives this both for wrong details and for a login that never arrived,
				// so try the same details through XML-RPC, which reads them from the request body.
				$xml = self::xmlrpcLogin( $url, $user, $pass );
				if ( 'ok' === $xml ) {
					return __( 'The user name and application password are right (checked another way), but the site\'s REST API doesn\'t see them. Either the server drops the Authorization header (on Apache, add this line to .htaccess above the WordPress rules: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1), or a security plugin there blocks REST API logins.', 'pulse-question' );
				}
				if ( 'refused' === $xml ) {
					/* translators: %s: user name. */
					return sprintf( __( 'The site refused the user name or application password. Check that %s is the login name (or email) of the account that made the application password, and make a new application password on that site under Users → Profile → Application Passwords; your normal login password won\'t work. If there\'s no Application Passwords section there, they\'re switched off: the site isn\'t on https:// or a security plugin turned them off.', 'pulse-question' ), $user );
				}
				return __( 'The site answered as if no login was sent. In order of likelihood: (1) the user name or application password is wrong (make a new application password under Users → Profile on that site; your normal password won\'t work); (2) application passwords are switched off there (no "Application Passwords" section under Users → Profile: the site isn\'t on https:// or a security plugin turned them off); (3) a security plugin blocks REST API logins; (4) the server drops the Authorization header (on Apache, add this line to .htaccess above the WordPress rules: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1).', 'pulse-question' );
		}
		if ( '' !== $code ) {
			/* translators: 1: HTTP status, 2: error code, 3: message. */
			return sprintf( __( 'The site answered HTTP %1$d (%2$s): %3$s', 'pulse-question' ), $r['code'], $code, (string) ( $r['body']['message'] ?? '' ) );
		}
		/* translators: %d: HTTP status. */
		return sprintf( __( 'That address didn\'t answer like a WordPress site\'s REST API (HTTP %d). Check the address, and that the REST API isn\'t blocked there.', 'pulse-question' ), $r['code'] );
	}

	/**
	 * Check the list ids in Settings against the lists that exist.
	 *
	 * @param array<int, string>|null $lists Id => title, or null if unknown.
	 * @return list<array{ok: bool|null, text: string}>
	 */
	private static function checkIds( ?array $lists ): array {
		$steps = array();
		$want  = array(
			'list_ids'           => __( 'Daily email list', 'pulse-question' ),
			'marketing_list_ids' => __( 'Marketing list', 'pulse-question' ),
		);
		$all   = '';
		if ( $lists ) {
			$names = array();
			foreach ( $lists as $id => $title ) {
				$names[] = $id . ' (' . $title . ')';
			}
			$all = implode( ', ', $names );
		}
		foreach ( $want as $key => $label ) {
			$ids = array_values( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) Settings::get( $key ) ) ?: array() ) ) );
			if ( ! $ids ) {
				if ( 'list_ids' === $key ) {
					/* translators: %s: lists that exist. */
					$steps[] = self::step( null, __( 'No list ids are set, so members are added as contacts but not to a list.', 'pulse-question' ) . ( '' !== $all ? ' ' . sprintf( __( 'Lists there: %s.', 'pulse-question' ), $all ) : '' ) );
				}
				continue;
			}
			if ( null === $lists ) {
				continue; // Can't tell.
			}
			$missing = array_diff( $ids, array_keys( $lists ) );
			if ( $missing ) {
				/* translators: 1: label, 2: ids, 3: lists that exist. */
				$steps[] = self::step( false, sprintf( __( '%1$s: id %2$s isn\'t in FluentCRM. Lists there: %3$s.', 'pulse-question' ), $label, implode( ', ', $missing ), '' !== $all ? $all : __( 'none', 'pulse-question' ) ) );
			} else {
				$names = array_map( static fn ( int $id ): string => $id . ' (' . $lists[ $id ] . ')', $ids );
				/* translators: 1: label, 2: lists. */
				$steps[] = self::step( true, sprintf( __( '%1$s found: %2$s.', 'pulse-question' ), $label, implode( ', ', $names ) ) );
			}
		}
		return $steps;
	}

	/**
	 * GET a REST route on the other site, without following redirects (they drop the login).
	 *
	 * @return array{code: int, body: mixed}|string The answer, or what went wrong.
	 */
	private static function get( string $url, string $route, string $user, string $pass ): array|string {
		$response = wp_remote_get(
			add_query_arg( 'rest_route', $route, $url . '/' ),
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: 1: site, 2: error. */
			return sprintf( __( 'Couldn\'t reach %1$s: %2$s', 'pulse-question' ), (string) wp_parse_url( $url, PHP_URL_HOST ), $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			$to   = (string) wp_remote_retrieve_header( $response, 'location' );
			$base = self::baseOf( $to );
			if ( '' !== $base && untrailingslashit( $base ) !== $url ) {
				/* translators: 1: address, 2: suggested address. */
				return sprintf( __( '%1$s redirects to %2$s, and the login is lost on the way. Change the site address to %2$s and save.', 'pulse-question' ), $url, $base );
			}
			/* translators: %s: where it redirects. */
			return sprintf( __( 'The site redirected the request (to %s), and the login is lost on the way. A security or redirect plugin there may be sending REST API requests elsewhere.', 'pulse-question' ), '' !== $to ? $to : '?' );
		}
		return array(
			'code' => $code,
			'body' => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
		);
	}

	/**
	 * Try the details through XML-RPC (wp.getUsersBlogs).
	 *
	 * @return string ok (accepted), refused (wrong details), or unknown (XML-RPC off or blocked).
	 */
	private static function xmlrpcLogin( string $url, string $user, string $pass ): string {
		$body     = '<?xml version="1.0"?><methodCall><methodName>wp.getUsersBlogs</methodName><params><param><value><string>' . esc_xml( $user ) . '</string></value></param><param><value><string>' . esc_xml( $pass ) . '</string></value></param></params></methodCall>';
		$response = wp_remote_post(
			$url . '/xmlrpc.php',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'text/xml' ),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'unknown';
		}
		$xml = (string) wp_remote_retrieve_body( $response );
		if ( ! str_contains( $xml, '<methodResponse>' ) ) {
			return 'unknown';
		}
		if ( ! str_contains( $xml, '<fault>' ) ) {
			return 'ok';
		}
		// 403 is WordPress's "Incorrect username or password"; anything else (405: XML-RPC off) tells us nothing.
		return preg_match( '#<name>faultCode</name>\s*<value><int>403</int>#', $xml ) ? 'refused' : 'unknown';
	}

	/** The site address a redirect points to (scheme, host, path; no query). */
	private static function baseOf( string $location ): string {
		$p = wp_parse_url( $location );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		$path = isset( $p['path'] ) ? untrailingslashit( (string) $p['path'] ) : '';
		$path = (string) preg_replace( '#/wp-json(/.*)?$#', '', $path );
		return ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path;
	}

	/** @return array{ok: bool|null, text: string} */
	private static function step( ?bool $ok, string $text ): array {
		return array(
			'ok'   => $ok,
			'text' => $text,
		);
	}
}
