<?php
/**
 * Sign in with Google: members join or log in with their Google account instead of a password.
 *
 * OpenID Connect authorization code flow with PKCE, through admin-post.php (never page-cached):
 * the button links to admin-post.php?action=pulse_question_google&redirect_to=…, which sends the
 * visitor to Google; Google sends them back to the same address with a code, which is exchanged
 * for the member's verified email address and name.
 *
 * A Google account is matched to a member by its Google id, then by verified email address. If
 * there's no match and Anyone can register is on, a member is created with the default role.
 * Accounts that can edit posts (staff) aren't linked by email: they keep using their password. New
 * members see the mailing list boxes with their first answer (Members::wasAsked()).
 *
 * The client id and secret come from Settings → Members, or from the PULSE_QUESTION_GOOGLE_CLIENT_ID
 * and PULSE_QUESTION_GOOGLE_CLIENT_SECRET constants in wp-config.php.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Members;

use PulseQuestion\Settings;

final class GoogleLogin {

	public const META_SUB = '_pulse_question_google_sub';

	private const ACTION   = 'pulse_question_google';
	private const COOKIE   = 'pulse_question_google_state';
	private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN    = 'https://oauth2.googleapis.com/token';
	private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'login_form', array( self::class, 'loginButton' ) );
		add_action( 'register_form', array( self::class, 'loginButton' ) );
		add_action( 'login_enqueue_scripts', array( self::class, 'loginStyle' ) );
		add_filter( 'wp_login_errors', array( self::class, 'loginError' ) );
	}

	public static function clientId(): string {
		if ( defined( 'PULSE_QUESTION_GOOGLE_CLIENT_ID' ) && is_string( PULSE_QUESTION_GOOGLE_CLIENT_ID ) && '' !== PULSE_QUESTION_GOOGLE_CLIENT_ID ) {
			return PULSE_QUESTION_GOOGLE_CLIENT_ID;
		}
		return trim( (string) Settings::get( 'google_client_id' ) );
	}

	public static function clientSecret(): string {
		if ( defined( 'PULSE_QUESTION_GOOGLE_CLIENT_SECRET' ) && is_string( PULSE_QUESTION_GOOGLE_CLIENT_SECRET ) && '' !== PULSE_QUESTION_GOOGLE_CLIENT_SECRET ) {
			return PULSE_QUESTION_GOOGLE_CLIENT_SECRET;
		}
		return trim( (string) Settings::get( 'google_client_secret' ) );
	}

	public static function secretFromConstant(): bool {
		return defined( 'PULSE_QUESTION_GOOGLE_CLIENT_SECRET' ) && '' !== PULSE_QUESTION_GOOGLE_CLIENT_SECRET;
	}

	public static function isConfigured(): bool {
		return '' !== self::clientId() && '' !== self::clientSecret();
	}

	/** The address to add under "Authorized redirect URIs" in Google Cloud. */
	public static function redirectUri(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION );
	}

	/** Where the button goes: Google, then back to $back. */
	public static function startUrl( string $back ): string {
		return add_query_arg( 'redirect_to', rawurlencode( $back ), self::redirectUri() );
	}

	/** The "Continue with Google" button, or '' when it isn't set up. */
	public static function button( string $back ): string {
		if ( ! self::isConfigured() ) {
			return '';
		}
		return '<a class="pq-button pq-google" href="' . esc_url( self::startUrl( $back ) ) . '">' . self::logo() . '<span>' . esc_html__( 'Continue with Google', 'pulse-question' ) . '</span></a>';
	}

	private static function logo(): string {
		return '<svg class="pq-google__logo" viewBox="0 0 48 48" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
	}

	/** On wp-login.php: the button above the login and sign-up forms. */
	public static function loginButton(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- where to go afterwards; validated below.
		$back = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ), home_url( '/' ) ) : home_url( '/' );
		$html = self::button( $back );
		if ( '' !== $html ) {
			// WordPress's hook comes after the password field, so: "or", then the button.
			echo '<div class="pq-google-login"><p class="pq-google-login__or"><span>' . esc_html__( 'or', 'pulse-question' ) . '</span></p>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		}
	}

	public static function loginStyle(): void {
		if ( ! self::isConfigured() ) {
			return;
		}
		wp_register_style( 'pulse-question-login', false, array(), \PulseQuestion\App::version() );
		wp_enqueue_style( 'pulse-question-login' );
		wp_add_inline_style(
			'pulse-question-login',
			'.pq-google-login{margin:0 0 16px}.pq-google-login .pq-google{margin-top:12px}.pq-google{display:flex;align-items:center;justify-content:center;gap:10px;padding:10px 12px;border:1px solid #dadce0;border-radius:4px;background:#fff;color:#1f1f1f;font-weight:500;text-decoration:none}.pq-google:hover,.pq-google:focus{background:#f8f9fa;color:#1f1f1f}.pq-google-login__or{display:flex;align-items:center;gap:8px;margin:0;color:#646970;font-size:12px}.pq-google-login__or::before,.pq-google-login__or::after{content:"";flex:1;border-top:1px solid #dcdcde}'
		);
	}

	/** Show why a Google sign-in didn't work, on the login page. */
	public static function loginError( \WP_Error $errors ): \WP_Error {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message code only.
		$code = isset( $_GET['pulse_question_google'] ) ? sanitize_key( (string) $_GET['pulse_question_google'] ) : '';
		$messages = array(
			'closed'   => __( 'New members can\'t sign up at the moment.', 'pulse-question' ),
			'unverified' => __( 'Your Google account\'s email address isn\'t verified yet, so it can\'t be used to sign in.', 'pulse-question' ),
			'staff'      => __( 'This account signs in with its password, not with Google.', 'pulse-question' ),
			'failed'   => __( 'Signing in with Google didn\'t work. Please try again.', 'pulse-question' ),
		);
		if ( isset( $messages[ $code ] ) ) {
			$errors->add( 'pulse_question_google', $messages[ $code ] );
		}
		return $errors;
	}

	/** admin-post.php?action=pulse_question_google: start, or come back from Google. */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth: the state parameter, bound to a cookie, protects the return.
		if ( ! self::isConfigured() ) {
			self::fail( 'failed' );
		}
		if ( isset( $_GET['code'] ) || isset( $_GET['error'] ) ) {
			self::finish(
				isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '',
				isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : ''
			);
			return;
		}
		$back = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ), home_url( '/' ) ) : home_url( '/' );
		// phpcs:enable
		self::start( $back );
	}

	private static function start( string $back ): void {
		$state    = wp_generate_password( 32, false );
		$verifier = wp_generate_password( 64, false );
		set_transient(
			'pulse_question_google_' . hash( 'sha256', $state ),
			array(
				'verifier' => $verifier,
				'back'     => $back,
			),
			15 * MINUTE_IN_SECONDS
		);
		setcookie( self::COOKIE, $state, time() + 15 * MINUTE_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', (string) COOKIE_DOMAIN, is_ssl(), true );
		$url = add_query_arg(
			array(
				'client_id'             => rawurlencode( self::clientId() ),
				'redirect_uri'          => rawurlencode( self::redirectUri() ),
				'response_type'         => 'code',
				'scope'                 => rawurlencode( 'openid email profile' ),
				'state'                 => rawurlencode( $state ),
				'code_challenge'        => rawurlencode( rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE needs base64url.
				'code_challenge_method' => 'S256',
				'prompt'                => 'select_account',
			),
			self::AUTH_URL
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google's sign-in page.
		exit;
	}

	private static function finish( string $state, string $code ): void {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', (string) COOKIE_DOMAIN, is_ssl(), true );
		$key   = 'pulse_question_google_' . hash( 'sha256', $state );
		$saved = '' !== $state ? get_transient( $key ) : false;
		delete_transient( $key );
		// The state must be the one this browser started with, so nobody can sign someone else in.
		if ( '' === $code || ! is_array( $saved ) || '' === $cookie || ! hash_equals( $cookie, $state ) ) {
			self::fail( 'failed' );
		}

		$profile = self::profile( $code, (string) $saved['verifier'] );
		if ( is_wp_error( $profile ) ) {
			self::fail( 'unverified' === $profile->get_error_code() ? 'unverified' : 'failed' );
		}
		$user_id = self::member( $profile );
		if ( is_wp_error( $user_id ) ) {
			self::fail( $user_id->get_error_code() );
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		/** WordPress's own hook, so other plugins see the login. */
		do_action( 'wp_login', (string) get_userdata( $user_id )->user_login, get_userdata( $user_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		wp_safe_redirect( (string) $saved['back'] );
		exit;
	}

	/**
	 * Exchange the code for the member's Google profile.
	 *
	 * @return array{sub: string, email: string, name: string, given_name: string}|\WP_Error
	 */
	private static function profile( string $code, string $verifier ): array|\WP_Error {
		$token = wp_remote_post(
			self::TOKEN,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => self::clientId(),
					'client_secret' => self::clientSecret(),
					'redirect_uri'  => self::redirectUri(),
					'grant_type'    => 'authorization_code',
					'code_verifier' => $verifier,
				),
			)
		);
		$body = is_wp_error( $token ) ? array() : json_decode( (string) wp_remote_retrieve_body( $token ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new \WP_Error( 'failed', 'token' );
		}
		$info = wp_remote_get(
			self::USERINFO,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ),
			)
		);
		$data = is_wp_error( $info ) ? array() : json_decode( (string) wp_remote_retrieve_body( $info ), true );
		if ( ! is_array( $data ) || empty( $data['sub'] ) || empty( $data['email'] ) ) {
			return new \WP_Error( 'failed', 'userinfo' );
		}
		if ( true !== ( $data['email_verified'] ?? false ) && 'true' !== ( $data['email_verified'] ?? '' ) ) {
			return new \WP_Error( 'unverified', 'email' );
		}
		return array(
			'sub'        => (string) $data['sub'],
			'email'      => sanitize_email( (string) $data['email'] ),
			'name'       => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'given_name' => sanitize_text_field( (string) ( $data['given_name'] ?? '' ) ),
		);
	}

	/**
	 * The member for this Google account: by Google id, then by email address, else a new one.
	 *
	 * @param array{sub: string, email: string, name: string, given_name: string} $profile Google profile.
	 */
	public static function member( array $profile ): int|\WP_Error {
		$found = get_users(
			array(
				'meta_key'   => self::META_SUB, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $profile['sub'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
		$user = get_user_by( 'email', $profile['email'] );
		if ( $user instanceof \WP_User ) {
			// Staff accounts keep signing in with their password: a Google account can't take one over.
			if ( user_can( $user, 'edit_posts' ) ) {
				return new \WP_Error( 'staff', 'staff' );
			}
			update_user_meta( $user->ID, self::META_SUB, $profile['sub'] );
			return $user->ID;
		}
		if ( ! get_option( 'users_can_register' ) ) {
			return new \WP_Error( 'closed', 'registration' );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::username( $profile['email'] ),
				'user_email'   => $profile['email'],
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => '' !== $profile['name'] ? $profile['name'] : strstr( $profile['email'], '@', true ),
				'first_name'   => $profile['given_name'],
				'nickname'     => '' !== $profile['given_name'] ? $profile['given_name'] : strstr( $profile['email'], '@', true ),
				'role'         => (string) get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new \WP_Error( 'failed', $user_id->get_error_message() );
		}
		update_user_meta( $user_id, self::META_SUB, $profile['sub'] );
		/**
		 * Fires when a member joins with Google. They're offered the mailing list with their first answer.
		 *
		 * @param int $user_id The new member.
		 */
		do_action( 'pulse_question_google_joined', $user_id );
		return $user_id;
	}

	/** A free username from the start of the email address. */
	public static function username( string $email ): string {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base = '' !== $base ? substr( $base, 0, 50 ) : 'member';
		$name = $base;
		$i    = 2;
		while ( username_exists( $name ) ) {
			$name = $base . $i;
			++$i;
		}
		return $name;
	}

	private static function fail( string $code ): never {
		wp_safe_redirect( add_query_arg( 'pulse_question_google', $code, wp_login_url() ) );
		exit;
	}
}
