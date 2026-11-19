<?php
/**
 * Questions → Settings, and the button that makes a page showing today's question and past ones.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

use PulseQuestion\Claude\Generator;
use PulseQuestion\Members\MailingList;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class SettingsPage {

	public const SLUG = 'pq-settings';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_pq_make_page', array( self::class, 'makePage' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Questions::POST_TYPE, __( 'Pulse Question Settings', 'pulse-question' ), __( 'Settings', 'pulse-question' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'pulse-question' ) );
		}
		$store = Settings::store();
		$flag  = static function ( string $key, string $label ) use ( $store ): void {
			echo '<label><input type="checkbox" name="' . esc_attr( $store->field( $key ) ) . '" value="1" ' . checked( (bool) $store->get( $key ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		};
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$made = isset( $_GET['pq_page_made'] ) ? absint( $_GET['pq_page_made'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pulse Question Settings', 'pulse-question' ); ?></h1>
			<?php if ( $made ) : ?>
				<div class="notice notice-success"><p>
					<?php esc_html_e( 'Page created.', 'pulse-question' ); ?>
					<a href="<?php echo esc_url( (string) get_permalink( $made ) ); ?>"><?php esc_html_e( 'View it', 'pulse-question' ); ?></a> ·
					<a href="<?php echo esc_url( (string) get_edit_post_link( $made ) ); ?>"><?php esc_html_e( 'Edit it', 'pulse-question' ); ?></a>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( $store->group() ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pq-switch"><?php esc_html_e( 'New question each day at', 'pulse-question' ); ?></label></th>
						<td>
							<input id="pq-switch" type="time" name="<?php echo esc_attr( $store->field( 'switch_time' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'switch_time' ) ); ?>" />
							<p class="description">
								<?php
								/* translators: %s: timezone name. */
								echo esc_html( sprintf( __( 'In the site\'s timezone (%s). It changes on the first visit or hourly check after this time.', 'pulse-question' ), wp_timezone_string() ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pq-label"><?php esc_html_e( 'Label', 'pulse-question' ); ?></label></th>
						<td><input id="pq-label" type="text" class="regular-text" name="<?php echo esc_attr( $store->field( 'label' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'label' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown above today\'s question, before the date.', 'pulse-question' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Approval', 'pulse-question' ); ?></th>
						<td><?php $flag( 'approve_mine', __( 'Questions I add are approved straight away', 'pulse-question' ) ); ?>
							<p class="description"><?php esc_html_e( 'Turn off to have every question wait for approval in the Queue first.', 'pulse-question' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pq-per-page"><?php esc_html_e( 'Past questions per page', 'pulse-question' ); ?></label></th>
						<td><input id="pq-per-page" type="number" min="3" max="60" class="small-text" name="<?php echo esc_attr( $store->field( 'per_page' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'per_page' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pq-low"><?php esc_html_e( 'Warn when the queue is short', 'pulse-question' ); ?></label></th>
						<td>
							<?php esc_html_e( 'Fewer than', 'pulse-question' ); ?>
							<input id="pq-low" type="number" min="0" max="60" class="small-text" name="<?php echo esc_attr( $store->field( 'low_queue_days' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'low_queue_days' ) ); ?>" />
							<?php esc_html_e( 'days of questions left (0 = never)', 'pulse-question' ); ?>
						</td>
					</tr>
					<?php if ( function_exists( 'todo_hub_add' ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'To-do list', 'pulse-question' ); ?></th>
							<td><?php $flag( 'todo_tasks', __( 'Add tasks to To-do Hub when the queue is short or questions wait for approval', 'pulse-question' ) ); ?></td>
						</tr>
					<?php else : ?>
						<input type="hidden" name="<?php echo esc_attr( $store->field( 'todo_tasks' ) ); ?>" value="<?php echo esc_attr( (string) (int) $store->get( 'todo_tasks' ) ); ?>" />
					<?php endif; ?>
					<?php self::claudeRows( $store, $flag ); ?>
					<?php self::answerRows( $store, $flag ); ?>
					<?php self::memberRows( $store, $flag ); ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Styles', 'pulse-question' ); ?></th>
						<td><?php $flag( 'load_styles', __( 'Use the plugin\'s styles', 'pulse-question' ) ); ?>
							<p class="description"><?php esc_html_e( 'They follow Automatic.css variables when the site has them, and your own styles always win over them. Turn them off to style everything yourself, for example in Etch; the markup uses pq- classes.', 'pulse-question' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'pulse-question' ); ?></th>
						<td><?php $flag( 'remove_data', __( 'Delete all questions, their categories and these settings when the plugin is deleted', 'pulse-question' ) ); ?></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Show it on your site', 'pulse-question' ); ?></h2>
			<p><?php esc_html_e( 'Add the "Question of the day" and "Past questions" blocks to any page or template, or use the shortcodes [pulse_question] and [pulse_question_past]. Each question also has its own page, and all asked questions are listed at /questions/.', 'pulse-question' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pq_make_page" />
				<?php wp_nonce_field( 'pq_make_page' ); ?>
				<label><input type="checkbox" name="front" value="1" /> <?php esc_html_e( 'and make it the home page', 'pulse-question' ); ?></label>
				<p><?php submit_button( __( 'Create a page with today\'s question and past questions', 'pulse-question' ), 'secondary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * The "Questions written by Claude" rows of the settings form.
	 *
	 * @param callable(string, string): void $flag Checkbox printer.
	 */
	private static function claudeRows( $store, callable $flag ): void {
		$constant = Generator::keyFromConstant();
		$saved    = '' !== (string) $store->get( 'claude_api_key' );
		?>
		<tr>
			<th scope="row" colspan="2"><h2 style="margin:1em 0 0"><?php esc_html_e( 'Questions written by Claude', 'pulse-question' ); ?></h2></th>
		</tr>
		<tr>
			<th scope="row"><label for="pq-claude-key"><?php esc_html_e( 'Anthropic API key', 'pulse-question' ); ?></label></th>
			<td>
				<?php if ( $constant ) : ?>
					<p><?php echo wp_kses_post( __( 'Set in <code>wp-config.php</code> (<code>PULSE_QUESTION_ANTHROPIC_API_KEY</code>).', 'pulse-question' ) ); ?></p>
				<?php else : ?>
					<input id="pq-claude-key" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $store->field( 'claude_api_key' ) ); ?>" value="" placeholder="<?php echo esc_attr( $saved ? __( 'Saved. Leave blank to keep it.', 'pulse-question' ) : 'sk-ant-…' ); ?>" />
					<?php if ( $saved ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $store->field( 'claude_api_key_clear' ) ); ?>" value="1" /> <?php esc_html_e( 'Remove the saved key', 'pulse-question' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php echo wp_kses_post( __( 'From <a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener">platform.claude.com</a>. To keep it out of the database, add <code>define( \'PULSE_QUESTION_ANTHROPIC_API_KEY\', \'…\' );</code> to <code>wp-config.php</code> instead. Each batch of 10 questions costs a few cents.', 'pulse-question' ) ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pq-claude-guidance"><?php esc_html_e( 'Guidance', 'pulse-question' ); ?></label></th>
			<td>
				<textarea id="pq-claude-guidance" class="large-text" rows="7" name="<?php echo esc_attr( $store->field( 'claude_guidance' ) ); ?>"><?php echo esc_textarea( (string) $store->get( 'claude_guidance' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'What kind of questions to write: subjects, tone, what to avoid. Leave empty to use the built-in guidance (big variety, politics only on rare occasions).', 'pulse-question' ); ?></p>
			</td>
		</tr>
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
		<tr><th scope="row" colspan="2"><h2 style="margin:1em 0 0"><?php esc_html_e( 'Answers and moderation', 'pulse-question' ); ?></h2></th></tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Answers', 'pulse-question' ); ?></th>
			<td>
				<?php $flag( 'answers_open', __( 'Members can answer, vote and reply on asked questions', 'pulse-question' ) ); ?>
				<p>
					<?php esc_html_e( 'Longest answer:', 'pulse-question' ); ?>
					<input type="number" min="0" max="10000" class="small-text" name="<?php echo esc_attr( $store->field( 'answer_max_length' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'answer_max_length' ) ); ?>" />
					<?php esc_html_e( 'characters (0 = no limit)', 'pulse-question' ); ?>
				</p>
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
					<label><input type="radio" name="<?php echo esc_attr( $store->field( 'moderation' ) ); ?>" value="wordpress" <?php checked( 'wordpress', $mode ); ?> /> <?php esc_html_e( 'WordPress\'s own moderation settings only (Settings → Discussion)', 'pulse-question' ); ?></label>
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

	/**
	 * Members and the mailing list.
	 *
	 * @param callable(string, string): void $flag Checkbox printer.
	 */
	private static function memberRows( $store, callable $flag ): void {
		$provider = (string) $store->get( 'list_provider' );
		?>
		<tr><th scope="row" colspan="2"><h2 style="margin:1em 0 0"><?php esc_html_e( 'Members and mailing list', 'pulse-question' ); ?></h2></th></tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Signing up', 'pulse-question' ); ?></th>
			<td>
				<?php if ( ! get_option( 'users_can_register' ) ) : ?>
					<p style="color:#b32d2e"><?php echo wp_kses_post( __( 'Visitors can\'t sign up yet. Turn on <strong>Anyone can register</strong> (as Subscriber) under Settings → General.', 'pulse-question' ) ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Visitors sign up on the WordPress sign-up page, which gets the mailing list boxes below. Members who signed up earlier tick them with their first answer.', 'pulse-question' ); ?></p>
				<?php endif; ?>
				<p><label><?php esc_html_e( 'Required box', 'pulse-question' ); ?><br /><input type="text" class="large-text" name="<?php echo esc_attr( $store->field( 'list_label' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'list_label' ) ); ?>" /></label></p>
				<p><label><?php esc_html_e( 'Optional marketing box (empty = don\'t show)', 'pulse-question' ); ?><br /><input type="text" class="large-text" name="<?php echo esc_attr( $store->field( 'marketing_label' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'marketing_label' ) ); ?>" /></label></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Mailing list', 'pulse-question' ); ?></th>
			<td>
				<fieldset>
					<label><input type="radio" name="<?php echo esc_attr( $store->field( 'list_provider' ) ); ?>" value="none" <?php checked( 'none', $provider ); ?> /> <?php esc_html_e( 'Not connected yet (joining is recorded on each member)', 'pulse-question' ); ?></label><br />
					<label><input type="radio" name="<?php echo esc_attr( $store->field( 'list_provider' ) ); ?>" value="fluentcrm" <?php checked( 'fluentcrm', $provider ); ?> /> <?php esc_html_e( 'FluentCRM on this site', 'pulse-question' ); ?><?php echo MailingList::localAvailable() ? '' : ' <em>(' . esc_html__( 'not active here', 'pulse-question' ) . ')</em>'; ?></label><br />
					<label><input type="radio" name="<?php echo esc_attr( $store->field( 'list_provider' ) ); ?>" value="fluentcrm_remote" <?php checked( 'fluentcrm_remote', $provider ); ?> /> <?php esc_html_e( 'FluentCRM on another site', 'pulse-question' ); ?></label>
				</fieldset>
				<p>
					<label><?php esc_html_e( 'List ids', 'pulse-question' ); ?> <input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $store->field( 'list_ids' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'list_ids' ) ); ?>" placeholder="3" /></label>
					<label><?php esc_html_e( 'Marketing list ids', 'pulse-question' ); ?> <input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $store->field( 'marketing_list_ids' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'marketing_list_ids' ) ); ?>" placeholder="1" /></label>
					<label><?php esc_html_e( 'Tag ids', 'pulse-question' ); ?> <input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $store->field( 'list_tag_ids' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'list_tag_ids' ) ); ?>" /></label>
				</p>
				<p class="description"><?php esc_html_e( 'The numbers FluentCRM shows for each list and tag (FluentCRM → Lists). Separate several with commas.', 'pulse-question' ); ?></p>
				<?php $flag( 'list_double_optin', __( 'Send FluentCRM\'s confirmation email (double opt-in)', 'pulse-question' ) ); ?>
				<div style="margin-top:12px">
					<p><strong><?php esc_html_e( 'FluentCRM on another site', 'pulse-question' ); ?></strong></p>
					<p><label><?php esc_html_e( 'Site address', 'pulse-question' ); ?> <input type="url" class="regular-text" name="<?php echo esc_attr( $store->field( 'list_remote_url' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'list_remote_url' ) ); ?>" placeholder="https://example.com" /></label></p>
					<p><label><?php esc_html_e( 'User name', 'pulse-question' ); ?> <input type="text" class="regular-text" name="<?php echo esc_attr( $store->field( 'list_remote_user' ) ); ?>" value="<?php echo esc_attr( (string) $store->get( 'list_remote_user' ) ); ?>" /></label></p>
					<p><label><?php esc_html_e( 'Application password', 'pulse-question' ); ?> <input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $store->field( 'list_remote_password' ) ); ?>" value="" placeholder="<?php echo esc_attr( '' !== (string) $store->get( 'list_remote_password' ) ? __( 'Saved. Leave blank to keep it.', 'pulse-question' ) : '' ); ?>" /></label></p>
					<p class="description"><?php esc_html_e( 'A user on that site who can manage FluentCRM contacts, with an application password (Users → Profile).', 'pulse-question' ); ?></p>
				</div>
				<?php
				$log = array_slice( MailingList::history(), 0, 5 );
				if ( $log ) {
					echo '<p><strong>' . esc_html__( 'Latest subscriptions', 'pulse-question' ) . '</strong></p><ul>';
					foreach ( $log as $row ) {
						$who = get_userdata( (int) $row['user'] );
						echo '<li>' . esc_html( wp_date( (string) get_option( 'date_format' ), (int) $row['time'] ) . ' · ' . ( $who ? $who->user_login : '#' . (int) $row['user'] ) . ' · ' ) . ( $row['ok'] ? esc_html__( 'added', 'pulse-question' ) : '<span style="color:#b32d2e">' . esc_html( (string) $row['error'] ) . '</span>' ) . '</li>';
					}
					echo '</ul>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	public static function makePage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'pulse-question' ), 403 );
		}
		check_admin_referer( 'pq_make_page' );
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
		wp_safe_redirect( add_query_arg( 'pq_page_made', $id, admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=' . self::SLUG ) ) );
		exit;
	}
}
