<?php
/**
 * Settings rows and buttons for the daily email and social sharing (on the Settings page).
 *
 * Buttons: preview the email, send yourself a test, send today's now, send a test post to the
 * social webhook. Each reports back in a notice at the top of Settings.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

use PulseQuestion\Daily;
use PulseQuestion\Email\Builtin;
use PulseQuestion\Email\DailyEmail;
use PulseQuestion\Email\FluentCampaign;
use PulseQuestion\Front\Render;
use PulseQuestion\Questions;
use PulseQuestion\Social\ShareImage;
use PulseQuestion\Social\ShareTags;
use PulseQuestion\Social\Webhook;

final class OutreachSettings {

	public static function init(): void {
		add_action( 'admin_post_pulse_question_email_preview', array( self::class, 'preview' ) );
		add_action( 'admin_post_pulse_question_email_test', array( self::class, 'test' ) );
		add_action( 'admin_post_pulse_question_email_send', array( self::class, 'sendNow' ) );
		add_action( 'admin_post_pulse_question_social_test', array( self::class, 'socialTest' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/** The logo picker, on the Settings page only. Tabs, saving and the other fields are WP Foundation's. */
	public static function assets( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . SettingsPage::SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'pulse-question-settings', \PulseQuestion\App::url( 'assets/admin-settings.js' ), array( 'jquery' ), \PulseQuestion\App::version(), true );
		wp_localize_script(
			'pulse-question-settings',
			'pulseQuestionSettings',
			array(
				'chooseLogo' => __( 'Choose a logo', 'pulse-question' ),
				'useLogo'    => __( 'Use this logo', 'pulse-question' ),
			)
		);
	}

	private static function actionUrl( string $action ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
	}

	private static function check( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'pulse-question' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** @param array{ok: bool, message: string} $result What happened. */
	private static function back( array $result, string $anchor ): void {
		set_transient( 'pulse_question_notice_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=' . SettingsPage::SLUG ) . '#' . $anchor );
		exit;
	}

	/** The result of the last button, at the top of Settings. */
	public static function notice(): void {
		$notice = get_transient( 'pulse_question_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( 'pulse_question_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $notice['ok'] ? 'success' : 'error', esc_html( (string) $notice['message'] ) );
	}

	public static function preview(): void {
		self::check( 'pulse_question_email_preview' );
		$today = Daily::current();
		if ( null === $today ) {
			wp_die( esc_html__( 'There\'s no question of the day yet.', 'pulse-question' ) );
		}
		$email = DailyEmail::content( $today['id'], $today['day'] );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo DailyEmail::wrap( $email, '#' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	public static function test(): void {
		self::check( 'pulse_question_email_test' );
		$today = Daily::current();
		$user  = wp_get_current_user();
		if ( null === $today ) {
			self::back(
				array(
					'ok'      => false,
					'message' => __( 'There\'s no question of the day yet.', 'pulse-question' ),
				),
				'pq-email'
			);
		}
		$email = DailyEmail::content( $today['id'], $today['day'] );
		$sent  = wp_mail(
			$user->user_email,
			/* translators: %s: subject. */
			sprintf( __( '[Test] %s', 'pulse-question' ), $email['subject'] ),
			DailyEmail::wrap( $email, '#' ),
			DailyEmail::headers()
		);
		self::back(
			array(
				'ok'      => $sent,
				'message' => $sent
					/* translators: %s: email address. */
					? sprintf( __( 'Test email sent to %s. (FluentCRM sends the real one with its own footer and unsubscribe link.)', 'pulse-question' ), $user->user_email )
					: __( 'WordPress couldn\'t send the test email. Check the site can send email (an SMTP plugin helps).', 'pulse-question' ),
			),
			'pq-email'
		);
	}

	public static function sendNow(): void {
		self::check( 'pulse_question_email_send' );
		self::back( DailyEmail::send(), 'pq-email' );
	}

	public static function socialTest(): void {
		self::check( 'pulse_question_social_test' );
		self::back( Webhook::post( true ), 'pq-social' );
	}

	/**
	 * @param \PulseQuestion\Vendor\Bignall\WPFoundation\Settings\Settings $store Settings.
	 * @param callable(string, string): void                                 $flag  Checkbox printer.
	 */
	public static function emailRows( $store, callable $flag ): void {
		$method = DailyEmail::method();
		$today  = Daily::stored();
		$state  = DailyEmail::state();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Send it', 'pulse-question' ); ?></th>
			<td>
				<?php $flag( 'email_enabled', __( 'Email each new question to the mailing list', 'pulse-question' ) ); ?>
				<p class="description">
					<?php
					if ( 'builtin' === $method ) {
						echo esc_html(
							sprintf(
								/* translators: %s: number of members. */
								_n( 'Sent by WordPress to the %s member who chose the daily email, 40 a minute. For more than a few hundred members, connect FluentCRM under Mailing list and use an email service.', 'Sent by WordPress to the %s members who chose the daily email, 40 a minute. For more than a few hundred members, connect FluentCRM under Mailing list and use an email service.', Builtin::recipientCount(), 'pulse-question' ),
								number_format_i18n( Builtin::recipientCount() )
							)
						);
					} else {
						$lists = FluentCampaign::listIds();
						echo esc_html(
							$lists
								/* translators: 1: list ids, 2: where. */
								? sprintf( __( 'Sent as a FluentCRM campaign to list %1$s (%2$s), to subscribed contacts only. FluentCRM adds its footer and unsubscribe link, and reports opens and clicks.', 'pulse-question' ), implode( ', ', $lists ), 'fluentcrm' === $method ? __( 'on this site', 'pulse-question' ) : __( 'on the mailing list site', 'pulse-question' ) )
								: __( 'Sent as a FluentCRM campaign, but no list ids are set under Mailing list yet.', 'pulse-question' )
						);
						if ( 'fluentcrm_remote' === $method ) {
							echo ' ' . esc_html__( 'The user under Mailing list needs FluentCRM\'s "Write and send emails" permission.', 'pulse-question' );
						}
					}
					?>
				</p>
				<?php if ( $store->get( 'email_enabled' ) && '' !== (string) $store->get( 'email_from' ) && ( null === $today || $today['day'] < (string) $store->get( 'email_from' ) ) ) : ?>
					<p class="description">
						<?php
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Automatic emails start with the question for %s. Use "Send today\'s email now" for today\'s.', 'pulse-question' ), Render::dayLabel( (string) $store->get( 'email_from' ) ) ) );
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-email-subject"><?php esc_html_e( 'Subject', 'pulse-question' ); ?></label></th>
			<td><input id="pq-email-subject" type="text" class="large-text" name="<?php echo esc_attr( $store->field( 'email_subject' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'email_subject' ) ); ?>" />
				<p class="description"><?php esc_html_e( '{question}, {date} and {site} are filled in.', 'pulse-question' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-email-intro"><?php esc_html_e( 'Opening line', 'pulse-question' ); ?></label></th>
			<td><textarea id="pq-email-intro" class="large-text" rows="2" name="<?php echo esc_attr( $store->field( 'email_intro' ) ); ?>"><?php echo esc_textarea( (string) $store->get( 'email_intro' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Optional, above the question.', 'pulse-question' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-email-button"><?php esc_html_e( 'Button', 'pulse-question' ); ?></label></th>
			<td><input id="pq-email-button" type="text" class="regular-text" name="<?php echo esc_attr( $store->field( 'email_button' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'email_button' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'From', 'pulse-question' ); ?></th>
			<td>
				<label><?php esc_html_e( 'Name', 'pulse-question' ); ?> <input type="text" class="regular-text" name="<?php echo esc_attr( $store->field( 'email_from_name' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'email_from_name' ) ); ?>" placeholder="<?php echo esc_attr( \PulseQuestion\Text::plain( (string) get_bloginfo( 'name' ) ) ); ?>" /></label>
				<label><?php esc_html_e( 'Address', 'pulse-question' ); ?> <input type="email" class="regular-text" name="<?php echo esc_attr( $store->field( 'email_from_address' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'email_from_address' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" /></label>
				<p class="description"><?php esc_html_e( 'For the built-in sender and test emails. Leave empty to use WordPress\'s (or your SMTP plugin\'s) sender. Use an address on your own domain so the email isn\'t marked as spam.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<?php if ( 'builtin' !== $method ) : ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'FluentCRM sender', 'pulse-question' ); ?></th>
			<td>
				<label><?php esc_html_e( 'Name', 'pulse-question' ); ?> <input type="text" class="regular-text" name="<?php echo esc_attr( $store->field( 'crm_from_name' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'crm_from_name' ) ); ?>" /></label>
				<label><?php esc_html_e( 'Address', 'pulse-question' ); ?> <input type="email" class="regular-text" name="<?php echo esc_attr( $store->field( 'crm_from_address' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'crm_from_address' ) ); ?>" /></label>
				<label><?php esc_html_e( 'Reply-to', 'pulse-question' ); ?> <input type="email" class="regular-text" name="<?php echo esc_attr( $store->field( 'crm_reply_to' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'crm_reply_to' ) ); ?>" /></label>
				<p class="description"><?php esc_html_e( 'Who the daily email comes from in FluentCRM. Fill in the name and address to use them (FluentCRM needs both), plus a reply-to if replies should go elsewhere. Use an address your FluentCRM site is set up to send from. Leave empty to use FluentCRM\'s own sender settings.', 'pulse-question' ); ?></p>
				<?php if ( '' !== trim( (string) $store->get( 'crm_from_name' ) ) && '' === (string) $store->get( 'crm_from_address' ) ) : ?>
					<p class="description"><strong><?php esc_html_e( 'Add the address too: with a name alone, FluentCRM uses its own sender.', 'pulse-question' ); ?></strong></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><label for="pq-email-top"><?php esc_html_e( 'Yesterday\'s answers', 'pulse-question' ); ?></label></th>
			<td><?php esc_html_e( 'Show the top', 'pulse-question' ); ?>
				<input id="pq-email-top" type="number" min="0" max="10" class="small-text" name="<?php echo esc_attr( $store->field( 'email_top_answers' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'email_top_answers' ) ); ?>" />
				<?php esc_html_e( 'answers to yesterday\'s question (0 = none)', 'pulse-question' ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Today', 'pulse-question' ); ?></th>
			<td>
				<?php if ( null !== $today && ( $state['day'] ?? '' ) === $today['day'] ) : ?>
					<p>
						<?php
						$status = (string) ( $state['status'] ?? '' );
						if ( 'sent' === $status ) {
							echo esc_html(
								isset( $state['recipients'] )
									/* translators: %s: number of people. */
									? sprintf( _n( 'Today\'s email has been sent to %s person.', 'Today\'s email has been sent to %s people.', (int) $state['recipients'], 'pulse-question' ), number_format_i18n( (int) $state['recipients'] ) )
									: __( 'Today\'s email has been sent.', 'pulse-question' )
							);
						} elseif ( 'sending' === $status ) {
							/* translators: 1: sent so far, 2: total. */
							echo esc_html( sprintf( __( 'Sending today\'s email: %1$s of %2$s so far.', 'pulse-question' ), number_format_i18n( (int) ( $state['sent'] ?? 0 ) ), number_format_i18n( (int) ( $state['recipients'] ?? 0 ) ) ) );
						} else {
							echo '<span style="color:#b32d2e">' . esc_html( (string) ( $state['error'] ?? '' ) ) . '</span>';
						}
						?>
					</p>
				<?php endif; ?>
				<p>
					<a class="button" target="_blank" href="<?php echo esc_url( self::actionUrl( 'pulse_question_email_preview' ) ); ?>"><?php esc_html_e( 'Preview', 'pulse-question' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::actionUrl( 'pulse_question_email_test' ) ); ?>"><?php esc_html_e( 'Send me a test', 'pulse-question' ); ?></a>
					<?php if ( null !== $today && ! DailyEmail::doneFor( $today['day'] ) ) : ?>
						<a class="button" href="<?php echo esc_url( self::actionUrl( 'pulse_question_email_send' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Send today\'s question to the whole mailing list now?', 'pulse-question' ) ); ?>');"><?php esc_html_e( 'Send today\'s email now', 'pulse-question' ); ?></a>
					<?php endif; ?>
				</p>
				<p class="description"><?php esc_html_e( 'Preview and the test use the saved settings, so save your changes first.', 'pulse-question' ); ?></p>
				<?php self::history( DailyEmail::history() ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param \PulseQuestion\Vendor\Bignall\WPFoundation\Settings\Settings $store Settings.
	 * @param callable(string, string): void                                 $flag  Checkbox printer.
	 */
	public static function socialRows( $store, callable $flag ): void {
		$today = Daily::stored();
		$seo   = ShareTags::seoPlugin();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'On the site', 'pulse-question' ); ?></th>
			<td>
				<?php $flag( 'share_buttons', __( 'Share links under each question (Facebook, X, LinkedIn, Pinterest, Bluesky, email, copy link)', 'pulse-question' ) ); ?><br />
				<?php $flag( 'share_tags', __( 'Share tags, so links to a question show it with its share image', 'pulse-question' ) ); ?>
				<?php if ( '' !== $seo ) : ?>
					<p class="description">
						<?php
						/* translators: %s: SEO plugin name. */
						echo esc_html( sprintf( __( '%s is writing the share tags, so the plugin only gives it the share image (Yoast SEO and Rank Math).', 'pulse-question' ), $seo ) );
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Share image', 'pulse-question' ); ?></th>
			<td>
				<?php
				$image = null !== $today ? ShareImage::forQuestion( $today['id'] ) : null;
				if ( $image ) {
					echo '<a href="' . esc_url( $image['url'] ) . '" target="_blank"><img src="' . esc_url( $image['url'] ) . '" alt="" width="400" style="max-width:100%;height:auto;border:1px solid #dcdcde;border-radius:6px" /></a>';
				} elseif ( ! ShareImage::supported() ) {
					echo '<p>' . esc_html__( 'This server\'s PHP has no GD image library with FreeType, so share images can\'t be made. Give a question a featured image to use that instead.', 'pulse-question' ) . '</p>';
				}
				?>
				<p class="description"><?php esc_html_e( 'Made for each question (1200×630). A question\'s featured image, if it has one, is used instead.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-social-url"><?php esc_html_e( 'Post each new question', 'pulse-question' ); ?></label></th>
			<td>
				<input id="pq-social-url" type="url" class="large-text" placeholder="https://hooks.zapier.com/…" name="<?php echo esc_attr( $store->field( 'social_webhook_url' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'social_webhook_url' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'A webhook address from Zapier, Make, n8n, Pabbly or IFTTT. Each new question is sent there with its link, a ready-written post and the share image, and your automation posts it to the networks you connect. Leave empty for no automatic posts. Posts start with the next question.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-social-text"><?php esc_html_e( 'Post text', 'pulse-question' ); ?></label></th>
			<td><textarea id="pq-social-text" class="large-text" rows="4" name="<?php echo esc_attr( $store->field( 'social_text' ) ); ?>"><?php echo esc_textarea( (string) $store->get( 'social_text' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( '{question}, {url}, {background}, {hashtags} and {site} are filled in. Sent as "text"; the parts are sent separately too.', 'pulse-question' ); ?></p>
				<p><label><?php esc_html_e( 'Hashtags', 'pulse-question' ); ?> <input type="text" class="regular-text" name="<?php echo esc_attr( $store->field( 'social_hashtags' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'social_hashtags' ) ); ?>" placeholder="QuestionOfTheDay, Journaling" /></label></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Check it', 'pulse-question' ); ?></th>
			<td>
				<?php if ( '' !== (string) $store->get( 'social_webhook_url' ) ) : ?>
					<p><a class="button" href="<?php echo esc_url( self::actionUrl( 'pulse_question_social_test' ) ); ?>"><?php esc_html_e( 'Send a test post', 'pulse-question' ); ?></a>
						<span class="description"><?php esc_html_e( 'Sends today\'s question marked "test": true, so you can map the fields in your automation.', 'pulse-question' ); ?></span></p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'To check a request really came from this site, compare the X-Pulse-Question-Signature header with sha256= and the HMAC-SHA256 of the body, keyed with:', 'pulse-question' ); ?>
					<code><?php echo esc_html( Webhook::secret() ); ?></code>
				</p>
				<?php self::history( Webhook::history() ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The logo choice: automatic, the bundled logo, or an image. Three options plus a media
	 * picker, so it's printed here rather than with a WP Foundation field.
	 *
	 * @param \PulseQuestion\Vendor\Bignall\WPFoundation\Settings\Settings $store Settings.
	 */
	public static function logoRow( $store ): void {
		$logo   = (int) $store->get( 'brand_logo' );
		$auto   = \PulseQuestion\Branding::automaticSource();
		$chosen = $logo > 0 ? wp_get_attachment_image_url( $logo, 'medium' ) : '';
		$field  = $store->field( 'brand_logo' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Logo', 'pulse-question' ); ?></th>
			<td>
				<fieldset class="pq-logo-choice">
					<label><input type="radio" name="pulse_question_logo_mode" value="auto" <?php checked( 0, $logo ); ?> />
						<?php
						echo esc_html(
							match ( $auto ) {
								'logo'  => __( 'Automatic: your site\'s logo', 'pulse-question' ),
								'icon'  => __( 'Automatic: your site icon (no site logo is set)', 'pulse-question' ),
								default => __( 'Automatic: your site\'s logo or site icon (neither is set yet, so no logo)', 'pulse-question' ),
							}
						);
						?>
					</label><br />
					<label><input type="radio" name="pulse_question_logo_mode" value="bundled" <?php checked( -1, $logo ); ?> /> <?php esc_html_e( 'The Pulse Question logo', 'pulse-question' ); ?></label><br />
					<label><input type="radio" name="pulse_question_logo_mode" value="image" <?php checked( $logo > 0 ); ?> /> <?php esc_html_e( 'An image:', 'pulse-question' ); ?></label>
					<button type="button" class="button pq-logo-pick"><?php esc_html_e( 'Choose…', 'pulse-question' ); ?></button>
					<input type="hidden" class="pq-logo-value" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $logo ); ?>" />
					<p><img class="pq-logo-preview" src="<?php echo esc_url( (string) $chosen ); ?>" alt="" style="max-height:72px;width:auto;<?php echo $chosen ? '' : 'display:none'; ?>" /></p>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Shown on share images. A wide logo with a transparent background works best.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/** @param list<array<string, mixed>> $log Recent sends. */
	private static function history( array $log ): void {
		$log = array_slice( $log, 0, 5 );
		if ( ! $log ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'Recent', 'pulse-question' ) . '</strong></p><ul>';
		foreach ( $log as $row ) {
			$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] );
			echo '<li>' . esc_html( $when . ( ! empty( $row['test'] ) ? ' · ' . __( 'test', 'pulse-question' ) : '' ) . ' · ' ) . ( $row['ok'] ? esc_html( (string) $row['detail'] ) : '<span style="color:#b32d2e">' . esc_html( (string) $row['detail'] ) . '</span>' ) . '</li>';
		}
		echo '</ul>';
	}
}
