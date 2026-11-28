<?php
/**
 * Questions → Settings, and the button that makes a page showing today's question and past ones.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

use PulseQuestion\Claude\Generator;
use PulseQuestion\Members\ConnectionTest;
use PulseQuestion\Members\GoogleLogin;
use PulseQuestion\Members\MailingList;
use PulseQuestion\Questions;
use PulseQuestion\Settings;
use PulseQuestion\Vendor\Bignall\WPFoundation\Plugin;
use PulseQuestion\Vendor\Bignall\WPFoundation\Settings\Fields;
use PulseQuestion\Vendor\Bignall\WPFoundation\Settings\SettingsPage as Page;

final class SettingsPage {

	public const SLUG = 'pulse-question-settings';

	public static function init( Plugin $plugin ): void {
		$plugin->settingsPage( self::page() );
		add_action( 'admin_post_pulse_question_make_page', array( self::class, 'makePage' ) );
		add_action( 'admin_post_pulse_question_test_list', array( self::class, 'testList' ) );
		OutreachSettings::init();
	}

	/**
	 * The page: one tab per section, built on WP Foundation's SettingsPage. The tab ids are the
	 * anchors the setup checklist, To-do Hub tasks and the test buttons link to.
	 */
	public static function page(): Page {
		$flag = array( self::class, 'flag' );
		return ( new Page( Settings::store(), self::SLUG, __( 'Pulse Question Settings', 'pulse-question' ) ) )
			->parent( 'edit.php?post_type=' . Questions::POST_TYPE, __( 'Settings', 'pulse-question' ) )
			->before( array( self::class, 'notices' ) )
			->tab( 'pq-general', __( 'General', 'pulse-question' ), array( self::class, 'generalRows' ) )
			->tab( 'pq-claude', __( 'Claude', 'pulse-question' ), array( self::class, 'claudeRows' ), __( 'Questions written by Claude', 'pulse-question' ) )
			->tab( 'pq-answers-settings', __( 'Answers', 'pulse-question' ), static fn ( Fields $f ) => self::answerRows( $f->settings(), $flag ), __( 'Answers and moderation', 'pulse-question' ) )
			->tab( 'pq-members', __( 'Members', 'pulse-question' ), array( self::class, 'memberRows' ) )
			->tab( 'pq-mailing-list', __( 'Mailing list', 'pulse-question' ), array( self::class, 'listRows' ) )
			->tab( 'pq-email', __( 'Daily email', 'pulse-question' ), static fn ( Fields $f ) => OutreachSettings::emailRows( $f->settings(), $flag ) )
			->tab( 'pq-social', __( 'Social', 'pulse-question' ), static fn ( Fields $f ) => OutreachSettings::socialRows( $f->settings(), $flag ), __( 'Social sharing', 'pulse-question' ) )
			->tab( 'pq-brand', __( 'Branding', 'pulse-question' ), array( self::class, 'brandRows' ) )
			->after( array( self::class, 'makePageForm' ) );
	}

	/** A checkbox for a setting, inside a row printed by hand. */
	public static function flag( string $key, string $label ): void {
		$store = Settings::store();
		echo '<label><input type="checkbox" name="' . esc_attr( $store->field( $key ) ) . '" value="1" ' . checked( (bool) $store->get( $key ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	/** Above the tabs: results of the buttons, the setup checklist and "Page created". */
	public static function notices(): void {
		OutreachSettings::notice();
		Setup::render();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$made = isset( $_GET['pulse_question_page_made'] ) ? absint( $_GET['pulse_question_page_made'] ) : 0;
		if ( $made ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Page created.', 'pulse-question' )
				. ' <a href="' . esc_url( (string) get_permalink( $made ) ) . '">' . esc_html__( 'View it', 'pulse-question' ) . '</a> · '
				. '<a href="' . esc_url( (string) get_edit_post_link( $made ) ) . '">' . esc_html__( 'Edit it', 'pulse-question' ) . '</a></p></div>';
		}
	}

	/** Below the settings form: the form the "Create a page" button in General belongs to. */
	public static function makePageForm(): void {
		echo '<form method="post" id="pq-make-page" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="pulse_question_make_page" />';
		wp_nonce_field( 'pulse_question_make_page' );
		echo '</form>';
	}

	public static function generalRows( Fields $f ): void {
		$f->time(
			'switch_time',
			__( 'New question each day at', 'pulse-question' ),
			/* translators: %s: timezone name. */
			esc_html( sprintf( __( 'In the site\'s timezone (%s). It changes on the first visit or hourly check after this time.', 'pulse-question' ), wp_timezone_string() ) )
		);
		$f->text( 'label', __( 'Label', 'pulse-question' ), esc_html__( 'Shown above today\'s question, before the date.', 'pulse-question' ) );
		$f->checkbox( 'approve_mine', __( 'Approval', 'pulse-question' ), __( 'Questions I add are approved straight away', 'pulse-question' ), esc_html__( 'Turn off to have every question wait for approval in the Queue first.', 'pulse-question' ) );
		$f->number( 'per_page', __( 'Past questions per page', 'pulse-question' ), min: 3, max: 60 );
		$f->number( 'low_queue_days', __( 'Warn when the queue is short', 'pulse-question' ), min: 0, max: 60, before: __( 'Fewer than', 'pulse-question' ), after: __( 'days of questions left (0 = never)', 'pulse-question' ) );
		if ( function_exists( 'todo_hub_add' ) ) {
			$f->checkbox( 'todo_tasks', __( 'To-do list', 'pulse-question' ), __( 'Add tasks to To-do Hub when the queue is short or questions wait for approval', 'pulse-question' ) );
		} else {
			// Not shown, but kept: an unticked (missing) checkbox would turn it off.
			echo '<tr hidden><td colspan="2"><input type="hidden" name="' . esc_attr( $f->settings()->field( 'todo_tasks' ) ) . '" value="' . esc_attr( (string) (int) $f->settings()->get( 'todo_tasks' ) ) . '" /></td></tr>';
		}
		$f->checkbox( 'remove_data', __( 'Uninstall', 'pulse-question' ), __( 'Delete all questions, their categories and these settings when the plugin is deleted', 'pulse-question' ) );
		?>
		<tr id="pq-show" class="wpf-settings__group">
			<th scope="rowgroup" colspan="2">
				<h3><?php esc_html_e( 'Show it on your site', 'pulse-question' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Add the "Question of the day" and "Past questions" blocks to any page or template, or use the shortcodes [pulse_question] and [pulse_question_past]. Each question also has its own page, and all asked questions are listed at /questions/.', 'pulse-question' ); ?></p>
			</th>
		</tr>
		<?php
		$f->html(
			__( 'Make a page', 'pulse-question' ),
			static function (): void {
				echo '<p><label><input type="checkbox" form="pq-make-page" name="front" value="1" /> ' . esc_html__( 'and make it the home page', 'pulse-question' ) . '</label></p>';
				echo '<p><button type="submit" form="pq-make-page" class="button">' . esc_html__( 'Create a page with today\'s question and past questions', 'pulse-question' ) . '</button></p>';
			}
		);
	}

	/** Branding, and whether to use the plugin's styles at all. */
	public static function brandRows( Fields $f ): void {
		OutreachSettings::logoRow( $f->settings() );
		$f->color( 'brand_accent', __( 'Main colour', 'pulse-question' ), esc_html__( 'Buttons and links.', 'pulse-question' ), \PulseQuestion\Branding::DEFAULT_ACCENT );
		$f->color( 'brand_accent_2', __( 'Second colour', 'pulse-question' ), esc_html__( 'Labels and the pulse line. Both colours are used on the site, in the daily email and on share images. Leave them empty to follow your theme\'s Automatic.css colours on the site, with orange and teal elsewhere.', 'pulse-question' ), \PulseQuestion\Branding::DEFAULT_ACCENT_2 );
		$f->media(
			'brand_home_icon',
			__( 'Home screen icon', 'pulse-question' ),
			esc_html__( 'The icon iPhones and iPads show when someone adds your site to their home screen. Use a square image at least 180×180 pixels with a solid background and no rounded corners: the phone rounds them itself and fills see-through parts with black. Leave it unset to use the site icon.', 'pulse-question' ),
			__( 'Choose a home screen icon', 'pulse-question' ),
			__( 'Use the site icon', 'pulse-question' )
		);
		$f->checkbox( 'load_styles', __( 'Styles', 'pulse-question' ), __( 'Use the plugin\'s styles', 'pulse-question' ), esc_html__( 'They follow Automatic.css variables when the site has them, and your own styles always win over them. Turn them off to style everything yourself, for example in Etch; the markup uses pq- classes.', 'pulse-question' ) );
	}

	/** The "Questions written by Claude" tab. */
	public static function claudeRows( Fields $f ): void {
		$flag = array( self::class, 'flag' );
		if ( Generator::keyFromConstant() ) {
			$f->html(
				__( 'Anthropic API key', 'pulse-question' ),
				static function (): void {
					echo '<p>' . wp_kses_post( __( 'Set in <code>wp-config.php</code> (<code>PULSE_QUESTION_ANTHROPIC_API_KEY</code>).', 'pulse-question' ) ) . '</p>';
				}
			);
		} else {
			$f->secret(
				'claude_api_key',
				__( 'Anthropic API key', 'pulse-question' ),
				__( 'From <a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener">platform.claude.com</a>. To keep it out of the database, add <code>define( \'PULSE_QUESTION_ANTHROPIC_API_KEY\', \'…\' );</code> to <code>wp-config.php</code> instead. Each batch of 10 questions costs a few cents.', 'pulse-question' ),
				'sk-ant-…',
				remove: __( 'Remove the saved key', 'pulse-question' )
			);
		}
		$f->textarea( 'claude_guidance', __( 'Guidance', 'pulse-question' ), esc_html__( 'What kind of questions to write: subjects, tone, what to avoid. Leave empty to use the built-in guidance (big variety, politics only on rare occasions).', 'pulse-question' ), 7 );
		$store = $f->settings();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Keep the queue topped up', 'pulse-question' ); ?></th>
			<td>
				<?php $flag( 'claude_auto', __( 'Write more questions automatically when the queue gets short', 'pulse-question' ) ); ?>
				<p>
					<?php esc_html_e( 'When fewer than', 'pulse-question' ); ?>
					<input type="number" min="1" max="90" class="small-text" name="<?php echo esc_attr( $store->field( 'claude_target_days' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'claude_target_days' ) ); ?>" />
					<?php esc_html_e( 'days of questions are in the queue or waiting for approval, write', 'pulse-question' ); ?>
					<input type="number" min="1" max="30" class="small-text" name="<?php echo esc_attr( $store->field( 'claude_batch' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'claude_batch' ) ); ?>" />
					<?php esc_html_e( 'more.', 'pulse-question' ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'At most one batch every 6 hours, checked hourly. The same batch size is used for the button on the Queue page.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Approval', 'pulse-question' ); ?></th>
			<td>
				<?php $flag( 'claude_auto_approve', __( 'Approve Claude\'s questions straight away', 'pulse-question' ) ); ?>
				<p class="description"><?php esc_html_e( 'Off: they wait for your approval in the Queue, so nothing is asked that you haven\'t read.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-claude-model"><?php esc_html_e( 'Model and effort', 'pulse-question' ); ?></label></th>
			<td>
				<input id="pq-claude-model" type="text" class="regular-text code" name="<?php echo esc_attr( $store->field( 'claude_model' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'claude_model' ) ); ?>" />
				<select name="<?php echo esc_attr( $store->field( 'claude_effort' ) ); ?>">
					<?php foreach ( array( 'low', 'medium', 'high' ) as $effort ) : ?>
						<option value="<?php echo esc_attr( $effort ); ?>" <?php selected( $store->get( 'claude_effort' ), $effort ); ?>><?php echo esc_html( ucfirst( $effort ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Claude Opus 5.5 at medium effort is the default.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<?php
		$log = array_slice( Generator::history(), 0, 8 );
		if ( $log ) :
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Recent batches', 'pulse-question' ); ?></th>
				<td>
					<table class="widefat striped" style="max-width:720px">
						<thead><tr>
							<th><?php esc_html_e( 'When', 'pulse-question' ); ?></th>
							<th><?php esc_html_e( 'How', 'pulse-question' ); ?></th>
							<th><?php esc_html_e( 'Result', 'pulse-question' ); ?></th>
							<th><?php esc_html_e( 'Tokens in / out', 'pulse-question' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $log as $row ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $row['time'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( 'auto' === ( $row['trigger'] ?? '' ) ? __( 'Automatic', 'pulse-question' ) : __( 'Button', 'pulse-question' ) ); ?></td>
								<td>
									<?php
									if ( '' !== (string) ( $row['error'] ?? '' ) ) {
										echo '<span style="color:#b32d2e">' . esc_html( (string) $row['error'] ) . '</span>';
									} else {
										/* translators: 1: questions added, 2: repeats skipped, 3: model. */
										echo esc_html( sprintf( __( '%1$d added, %2$d repeats skipped (%3$s)', 'pulse-question' ), (int) $row['added'], (int) $row['skipped'], (string) $row['model'] ) );
									}
									?>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) ( $row['input'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $row['output'] ?? 0 ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</td>
			</tr>
		<?php endif; ?>
		<?php
	}

	/**
	 * Answers and moderation.
	 *
	 * @param callable(string, string): void $flag Checkbox printer.
	 */
	private static function answerRows( $store, callable $flag ): void {
		$mode = (string) $store->get( 'moderation' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Answers', 'pulse-question' ); ?></th>
			<td>
				<?php $flag( 'answers_open', __( 'Members can answer, vote and reply on asked questions', 'pulse-question' ) ); ?>
				<p>
					<?php esc_html_e( 'Longest answer:', 'pulse-question' ); ?>
					<input type="number" min="0" max="10000" class="small-text" name="<?php echo esc_attr( $store->field( 'answer_max_length' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'answer_max_length' ) ); ?>" />
					<?php esc_html_e( 'characters (0 = no limit)', 'pulse-question' ); ?>
				</p>
				<p>
					<label for="pq-home-top"><?php esc_html_e( 'Top answers under today\'s question:', 'pulse-question' ); ?></label>
					<input id="pq-home-top" type="number" min="0" max="10" class="small-text" name="<?php echo esc_attr( $store->field( 'home_top_answers' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'home_top_answers' ) ); ?>" />
					<?php esc_html_e( '(0 = none; until today has answers, yesterday\'s are shown)', 'pulse-question' ); ?>
				</p>
				<p>
					<label for="pq-home-recent"><?php esc_html_e( 'Recent answers to any question:', 'pulse-question' ); ?></label>
					<input id="pq-home-recent" type="number" min="0" max="20" class="small-text" name="<?php echo esc_attr( $store->field( 'home_recent_answers' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'home_recent_answers' ) ); ?>" />
					<?php esc_html_e( '(0 = none)', 'pulse-question' ); ?>
				</p>
				<?php $flag( 'home_answer_form', __( 'Show the answer box under today\'s question, not only on its own page', 'pulse-question' ) ); ?><br />
				<?php $flag( 'answer_emails', __( 'Email me about every new answer', 'pulse-question' ) ); ?>
				<p class="description"><?php esc_html_e( 'Off by default. Held answers still follow WordPress\'s "email me when a comment is held" setting (Settings → Discussion).', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Moderation', 'pulse-question' ); ?></th>
			<td>
				<fieldset>
					<label><input type="radio" name="<?php echo esc_attr( $store->field( 'moderation' ) ); ?>" value="claude" <?php checked( 'claude', $mode ); ?> /> <strong><?php esc_html_e( 'Automatic, with Claude', 'pulse-question' ); ?></strong> <?php esc_html_e( '(recommended): clean answers appear straight away, unclear ones wait for you, clear violations are kept off the site', 'pulse-question' ); ?></label><br />
					<label><input type="radio" name="<?php echo esc_attr( $store->field( 'moderation' ) ); ?>" value="hold" <?php checked( 'hold', $mode ); ?> /> <?php esc_html_e( 'Hold every answer for me', 'pulse-question' ); ?></label><br />
					<label><input type="radio" name="<?php echo esc_attr( $store->field( 'moderation' ) ); ?>" value="wordpress" <?php checked( 'wordpress', $mode ); // phpcs:ignore WordPress.WP.CapitalPDangit -- a setting value. ?> /> <?php esc_html_e( 'WordPress\'s own moderation settings only (Settings → Discussion)', 'pulse-question' ); ?></label>
				</fieldset>
				<?php if ( 'claude' === $mode && ! Generator::isConfigured() ) : ?>
					<p style="color:#b32d2e"><?php esc_html_e( 'No Anthropic API key yet, so every answer is held until you add one (under "Questions written by Claude").', 'pulse-question' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Each answer takes a few seconds to check. If the check can\'t run, the answer is held and checked again later. Akismet, if active, still catches spam first.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-guidelines"><?php esc_html_e( 'Community guidelines', 'pulse-question' ); ?></label></th>
			<td>
				<textarea id="pq-guidelines" class="large-text" rows="6" name="<?php echo esc_attr( $store->field( 'moderation_guidelines' ) ); ?>"><?php echo esc_textarea( (string) $store->get( 'moderation_guidelines' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'What the automatic check allows, removes and sends to you. Leave empty for the built-in guidelines (no swearing, insults, hate, sexual content, spam or personal details; anything unsure, any links and mentions of self-harm go to you).', 'pulse-question' ); ?></p>
				<p>
					<label><?php esc_html_e( 'Model', 'pulse-question' ); ?> <input type="text" class="regular-text code" name="<?php echo esc_attr( $store->field( 'moderation_model' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'moderation_model' ) ); ?>" /></label>
					<select name="<?php echo esc_attr( $store->field( 'moderation_effort' ) ); ?>">
						<?php foreach ( array( 'low', 'medium', 'high' ) as $effort ) : ?>
							<option value="<?php echo esc_attr( $effort ); ?>" <?php selected( $store->get( 'moderation_effort' ), $effort ); ?>><?php echo esc_html( ucfirst( $effort ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="description"><?php esc_html_e( 'Claude Opus 5.5 at low effort is the default: about half a cent per answer. A smaller model such as claude-haiku-5-5 costs much less per answer.', 'pulse-question' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-reports"><?php esc_html_e( 'Reports', 'pulse-question' ); ?></label></th>
			<td>
				<?php esc_html_e( 'Hide an answer for review after', 'pulse-question' ); ?>
				<input id="pq-reports" type="number" min="0" max="50" class="small-text" name="<?php echo esc_attr( $store->field( 'report_threshold' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'report_threshold' ) ); ?>" />
				<?php esc_html_e( 'members report it (0 = never hide)', 'pulse-question' ); ?>
			</td>
		</tr>
		<?php
	}

	/** Members: signing up and signing in with Google. */
	public static function memberRows( Fields $f ): void {
		$f->html(
			__( 'Signing up', 'pulse-question' ),
			static function (): void {
				if ( ! get_option( 'users_can_register' ) ) {
					echo '<p style="color:#b32d2e">' . wp_kses_post( __( 'Visitors can\'t sign up yet. Turn on <strong>Anyone can register</strong> (as Subscriber) under Settings → General.', 'pulse-question' ) ) . '</p>';
				} else {
					echo '<p>' . esc_html__( 'Visitors sign up on the WordPress sign-up page, which gets the boxes for joining the mailing list (set them under Mailing list). Both are optional. Members who signed up earlier see them once, with their first answer.', 'pulse-question' ) . '</p>';
				}
			}
		);
		$f->heading(
			__( 'Sign in with Google', 'pulse-question' ),
			__( 'Adds <strong>Continue with Google</strong> to the log-in and sign-up pages and the answer box. In <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener">Google Cloud → Clients</a>, create a <em>Web application</em> client and add this under <em>Authorized redirect URIs</em>:', 'pulse-question' )
				. '<br /><code>' . esc_html( GoogleLogin::redirectUri() ) . '</code>'
		);
		$f->text( 'google_client_id', __( 'Client ID', 'pulse-question' ), placeholder: __( '…apps.googleusercontent.com', 'pulse-question' ), class: 'large-text', guard: true );
		if ( GoogleLogin::secretFromConstant() ) {
			$f->html(
				__( 'Client secret', 'pulse-question' ),
				static function (): void {
					echo '<p>' . wp_kses_post( __( 'Set in <code>wp-config.php</code> (<code>PULSE_QUESTION_GOOGLE_CLIENT_SECRET</code>).', 'pulse-question' ) ) . '</p>';
				}
			);
		} else {
			$f->secret( 'google_client_secret', __( 'Client secret', 'pulse-question' ), remove: __( 'Remove the saved secret', 'pulse-question' ) );
		}
		$f->html(
			__( 'Status', 'pulse-question' ),
			static function (): void {
				echo GoogleLogin::isConfigured() ? '<strong style="color:#00a32a">' . esc_html__( 'On', 'pulse-question' ) . '</strong>' : '<em>' . esc_html__( 'Off until both are filled in.', 'pulse-question' ) . '</em>';
				echo '<p class="description">' . esc_html__( 'New members are created only when Anyone can register is on. Accounts that can edit posts keep signing in with their password.', 'pulse-question' ) . '</p>';
			}
		);
	}

	/** The mailing list: the boxes on the sign-up page and where joiners go. */
	public static function listRows( Fields $f ): void {
		$store = $f->settings();
		$f->text( 'list_label', __( 'Daily email box', 'pulse-question' ), class: 'large-text' );
		$f->text( 'marketing_label', __( 'Marketing box', 'pulse-question' ), esc_html__( 'Empty = don\'t show it.', 'pulse-question' ), class: 'large-text' );
		$f->checkbox( 'join_prechecked', __( 'Ticked', 'pulse-question' ), __( 'Tick both boxes to begin with', 'pulse-question' ), esc_html__( 'In the EU and UK, a ticked box doesn\'t count as consent, so turn this off if you have visitors there. Double opt-in (below) helps either way.', 'pulse-question' ) );

		$f->heading( __( 'Connection', 'pulse-question' ) );
		$f->radio(
			'list_provider',
			__( 'Mailing list', 'pulse-question' ),
			array(
				'none'             => __( 'Not connected yet (joining is recorded on each member)', 'pulse-question' ),
				'fluentcrm'        => __( 'FluentCRM on this site', 'pulse-question' ) . ( MailingList::localAvailable() ? '' : ' ' . __( '(not active here)', 'pulse-question' ) ),
				'fluentcrm_remote' => __( 'FluentCRM on another site', 'pulse-question' ),
			)
		);
		$f->html(
			__( 'Lists and tags', 'pulse-question' ),
			static function () use ( $store ): void {
				?>
				<p>
					<label><?php esc_html_e( 'List ids', 'pulse-question' ); ?> <input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $store->field( 'list_ids' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'list_ids' ) ); ?>" placeholder="3" /></label>
					<label><?php esc_html_e( 'Marketing list ids', 'pulse-question' ); ?> <input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $store->field( 'marketing_list_ids' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'marketing_list_ids' ) ); ?>" placeholder="1" /></label>
					<label><?php esc_html_e( 'Tag ids', 'pulse-question' ); ?> <input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $store->field( 'list_tag_ids' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'list_tag_ids' ) ); ?>" /></label>
				</p>
				<p class="description"><?php esc_html_e( 'The numbers FluentCRM shows for each list and tag (FluentCRM → Lists). Separate several with commas.', 'pulse-question' ); ?></p>
				<?php
			}
		);
		$f->checkbox( 'list_double_optin', __( 'Double opt-in', 'pulse-question' ), __( 'Send FluentCRM\'s confirmation email (double opt-in)', 'pulse-question' ) );

		$f->heading(
			__( 'FluentCRM on another site', 'pulse-question' ),
			esc_html__( 'A user on that site with an application password (Users → Profile). An administrator works. Otherwise, under FluentCRM → Settings → Managers, give the user: "Add, update and import contacts", "Create and update tags, lists, companies and segments" and, for the daily email, "Write and send emails".', 'pulse-question' )
		);
		$f->text( 'list_remote_url', __( 'Site address', 'pulse-question' ), placeholder: 'https://example.com', type: 'url' );
		$f->text( 'list_remote_user', __( 'User name', 'pulse-question' ), guard: true );
		$f->secret( 'list_remote_password', __( 'Application password', 'pulse-question' ), remove: __( 'Remove the saved password', 'pulse-question' ) );

		$f->html(
			__( 'Check it', 'pulse-question' ),
			static function (): void {
				?>
				<p id="pq-list-test">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pulse_question_test_list' ), 'pulse_question_test_list' ) ); ?>"><?php esc_html_e( 'Test connection', 'pulse-question' ); ?></a>
					<span class="description"><?php esc_html_e( 'Checks the saved settings, so save your changes first. Nobody is added to the list.', 'pulse-question' ); ?></span>
				</p>
				<?php
				$test = get_transient( 'pulse_question_list_test_' . get_current_user_id() );
				if ( is_array( $test ) ) {
					delete_transient( 'pulse_question_list_test_' . get_current_user_id() );
					echo '<ul class="pq-list-test">';
					foreach ( $test as $step ) {
						$mark  = true === $step['ok'] ? '✓' : ( false === $step['ok'] ? '✗' : '!' );
						$color = true === $step['ok'] ? '#00a32a' : ( false === $step['ok'] ? '#b32d2e' : '#996800' );
						echo '<li><span style="display:inline-block;width:1.2em;font-weight:600;color:' . esc_attr( $color ) . '">' . esc_html( $mark ) . '</span>' . esc_html( (string) $step['text'] ) . '</li>';
					}
					echo '</ul>';
				}
				$log = array_slice( MailingList::history(), 0, 5 );
				if ( $log ) {
					echo '<p><strong>' . esc_html__( 'Latest subscriptions', 'pulse-question' ) . '</strong></p><ul>';
					foreach ( $log as $row ) {
						$who = get_userdata( (int) $row['user'] );
						echo '<li>' . esc_html( wp_date( (string) get_option( 'date_format' ), (int) $row['time'] ) . ' · ' . ( $who ? $who->user_login : '#' . (int) $row['user'] ) . ' · ' ) . ( $row['ok'] ? esc_html__( 'added', 'pulse-question' ) : '<span style="color:#b32d2e">' . esc_html( (string) $row['error'] ) . '</span>' ) . '</li>';
					}
					echo '</ul>';
				}
			}
		);
	}

	/** Run the mailing list connection test and show the result under the button. */
	public static function testList(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'pulse-question' ), 403 );
		}
		check_admin_referer( 'pulse_question_test_list' );
		set_transient( 'pulse_question_list_test_' . get_current_user_id(), ConnectionTest::run(), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=' . self::SLUG ) . '#pq-list-test' );
		exit;
	}

	public static function makePage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'pulse-question' ), 403 );
		}
		check_admin_referer( 'pulse_question_make_page' );
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Question of the Day', 'pulse-question' ),
				'post_content' => "<!-- wp:pulse-question/today /-->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html__( 'Past questions', 'pulse-question' ) . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:pulse-question/past /-->",
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		if ( ! empty( $_POST['front'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}
		wp_safe_redirect( add_query_arg( 'pulse_question_page_made', $id, admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=' . self::SLUG ) ) );
		exit;
	}
}
