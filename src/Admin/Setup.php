<?php
/**
 * "Set up Pulse Question": a checklist at the top of Settings for a new site, and a notice
 * after activation that leads to it.
 *
 * Each step is ticked when it's done (or when the owner marks it done, for steps the plugin
 * can't see, such as showing the question in a page builder). Optional steps say so. The
 * checklist folds away once everything that matters is done, and can be hidden.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

use PulseQuestion\Branding;
use PulseQuestion\Claude\Generator;
use PulseQuestion\Members\MailingList;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class Setup {

	/** Steps the owner marked done, and whether the checklist is hidden. */
	public const OPTION = 'pulse_question_setup';

	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_pulse_question_setup', array( self::class, 'handle' ) );
	}

	/** @return array{done: list<string>, hidden: bool, seen: bool} */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'done'   => array_values( array_map( 'strval', (array) ( $state['done'] ?? array() ) ) ),
			'hidden' => ! empty( $state['hidden'] ),
			'seen'   => ! empty( $state['seen'] ),
		);
	}

	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	private static function settingsUrl( string $anchor = '' ): string {
		return admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=' . SettingsPage::SLUG ) . ( '' !== $anchor ? '#' . $anchor : '' );
	}

	/**
	 * The steps, in order.
	 *
	 * @return list<array{key: string, title: string, text: string, done: bool, optional: bool, link: string, action: string, markable: bool}>
	 */
	public static function steps(): array {
		$marked = self::state()['done'];
		$steps  = array(
			array(
				'key'      => 'questions',
				'title'    => __( 'Add your first questions', 'pulse-question' ),
				'text'     => __( 'Type a few in the Queue, one per line, or let Claude write some.', 'pulse-question' ),
				'done'     => count( Questions::queue() ) + count( Questions::asked() ) > 0,
				'optional' => false,
				'link'     => admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=pulse-question-queue' ),
				'action'   => __( 'Open the Queue', 'pulse-question' ),
				'markable' => false,
			),
			array(
				'key'      => 'page',
				'title'    => __( 'Show today\'s question on your site', 'pulse-question' ),
				'text'     => __( 'Make a page with today\'s question and past questions (and make it the home page if you like), or use the blocks or a page builder.', 'pulse-question' ),
				'done'     => self::hasPage() || in_array( 'page', $marked, true ),
				'optional' => false,
				'link'     => self::settingsUrl( 'pq-show' ),
				'action'   => __( 'Make the page', 'pulse-question' ),
				'markable' => true,
			),
			array(
				'key'      => 'claude',
				'title'    => __( 'Add a Claude API key', 'pulse-question' ),
				'text'     => __( 'For questions written by Claude and automatic checks of every answer. Without one, you write the questions and answers wait for you.', 'pulse-question' ),
				'done'     => Generator::isConfigured(),
				'optional' => true,
				'link'     => self::settingsUrl( 'pq-claude' ),
				'action'   => __( 'Add the key', 'pulse-question' ),
				'markable' => false,
			),
			array(
				'key'      => 'register',
				'title'    => __( 'Let visitors join', 'pulse-question' ),
				'text'     => __( 'Members answer, vote and get the daily email. Turn on "Anyone can register", with Subscriber as the new user role.', 'pulse-question' ),
				'done'     => (bool) get_option( 'users_can_register' ),
				'optional' => false,
				'link'     => admin_url( 'options-general.php' ),
				'action'   => __( 'Open Settings → General', 'pulse-question' ),
				'markable' => false,
			),
			array(
				'key'      => 'list',
				'title'    => __( 'Connect your mailing list', 'pulse-question' ),
				'text'     => __( 'FluentCRM on this site or another. Without it, members\' choices are kept on the site and the built-in sender emails them.', 'pulse-question' ),
				'done'     => 'none' !== MailingList::provider(),
				'optional' => true,
				'link'     => self::settingsUrl( 'pq-mailing-list' ),
				'action'   => __( 'Connect', 'pulse-question' ),
				'markable' => false,
			),
			array(
				'key'      => 'email',
				'title'    => __( 'Turn on the daily email', 'pulse-question' ),
				'text'     => __( 'Each new question goes to the mailing list, with yesterday\'s top answers.', 'pulse-question' ),
				'done'     => (bool) Settings::get( 'email_enabled' ),
				'optional' => false,
				'link'     => self::settingsUrl( 'pq-email' ),
				'action'   => __( 'Set it up', 'pulse-question' ),
				'markable' => false,
			),
			array(
				'key'      => 'brand',
				'title'    => __( 'Choose your logo and colours', 'pulse-question' ),
				'text'     => __( 'For share images and the daily email.', 'pulse-question' ),
				'done'     => 0 !== (int) Settings::get( 'brand_logo' ) || Branding::hasCustomColors() || 'none' !== Branding::automaticSource() || in_array( 'brand', $marked, true ),
				'optional' => true,
				'link'     => self::settingsUrl( 'pq-brand' ),
				'action'   => __( 'Choose', 'pulse-question' ),
				'markable' => true,
			),
			array(
				'key'      => 'social',
				'title'    => __( 'Post new questions to social media', 'pulse-question' ),
				'text'     => __( 'Through Zapier, Make, n8n or another automation tool.', 'pulse-question' ),
				'done'     => '' !== (string) Settings::get( 'social_webhook_url' ) || in_array( 'social', $marked, true ),
				'optional' => true,
				'link'     => self::settingsUrl( 'pq-social' ),
				'action'   => __( 'Set it up', 'pulse-question' ),
				'markable' => true,
			),
		);
		/**
		 * The setup checklist steps.
		 *
		 * @param list<array<string, mixed>> $steps Steps.
		 */
		return apply_filters( 'pulse_question_setup_steps', $steps );
	}

	/** Whether a published page shows today's question (block or shortcode). */
	public static function hasPage(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post','wp_template','wp_template_part','wp_block')
			AND ( post_content LIKE '%wp:pulse-question/today%' OR post_content LIKE '%[pulse\\_question%' ) LIMIT 1"
		);
	}

	public static function render(): void {
		$state = self::state();
		if ( ! $state['seen'] ) {
			$state['seen'] = true;
			self::save( $state );
		}
		$steps = self::steps();
		$left  = array_filter( $steps, static fn ( array $s ): bool => ! $s['done'] && ! $s['optional'] );
		$done  = count( array_filter( $steps, static fn ( array $s ): bool => $s['done'] ) );
		if ( $state['hidden'] ) {
			echo '<p><a href="' . esc_url( self::actionUrl( 'show' ) ) . '">' . esc_html__( 'Show the setup checklist', 'pulse-question' ) . '</a></p>';
			return;
		}
		?>
		<div class="pq-setup card" style="max-width:none;margin:16px 0;padding:8px 20px 16px">
			<details<?php echo $left ? ' open' : ''; ?>>
				<summary style="cursor:pointer">
					<h2 style="display:inline-block;margin:12px 0">
						<?php
						echo esc_html(
							$left
								? __( 'Set up Pulse Question', 'pulse-question' )
								: __( 'Pulse Question is set up', 'pulse-question' )
						);
						?>
					</h2>
					<span class="description">
						<?php
						/* translators: 1: steps done, 2: all steps. */
						echo esc_html( sprintf( __( '%1$s of %2$s done', 'pulse-question' ), number_format_i18n( $done ), number_format_i18n( count( $steps ) ) ) );
						?>
					</span>
				</summary>
				<ol style="margin:0 0 0 1.4em">
					<?php foreach ( $steps as $step ) : ?>
						<li style="margin:0 0 10px">
							<strong style="<?php echo $step['done'] ? 'color:#00a32a' : ''; ?>"><?php echo $step['done'] ? '✓ ' : ''; ?><?php echo esc_html( $step['title'] ); ?></strong>
							<?php if ( $step['optional'] ) : ?>
								<span class="description">(<?php esc_html_e( 'optional', 'pulse-question' ); ?>)</span>
							<?php endif; ?>
							<?php if ( ! $step['done'] ) : ?>
								<br /><span><?php echo esc_html( $step['text'] ); ?></span>
								<br /><a href="<?php echo esc_url( $step['link'] ); ?>"><?php echo esc_html( $step['action'] ); ?></a>
								<?php if ( $step['markable'] ) : ?>
									· <a href="<?php echo esc_url( self::actionUrl( 'done', $step['key'] ) ); ?>"><?php esc_html_e( 'I\'ve done this another way', 'pulse-question' ); ?></a>
								<?php endif; ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<p style="margin:4px 0 0">
					<a href="<?php echo esc_url( self::actionUrl( 'hide' ) ); ?>"><?php esc_html_e( 'Hide this checklist', 'pulse-question' ); ?></a>
					· <a href="https://bignall.github.io/pulse-question/" target="_blank" rel="noopener"><?php esc_html_e( 'Read the guide', 'pulse-question' ); ?></a>
				</p>
			</details>
		</div>
		<?php
	}

	private static function actionUrl( string $what, string $step = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'pulse_question_setup',
					'do'     => $what,
					'step'   => $step,
				),
				admin_url( 'admin-post.php' )
			),
			'pulse_question_setup'
		);
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'pulse-question' ), 403 );
		}
		check_admin_referer( 'pulse_question_setup' );
		$state = self::state();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
		$do   = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable
		if ( 'done' === $do && '' !== $step ) {
			$state['done'][] = $step;
			$state['done']   = array_values( array_unique( $state['done'] ) );
		} elseif ( 'hide' === $do ) {
			$state['hidden'] = true;
		} elseif ( 'show' === $do ) {
			$state['hidden'] = false;
		}
		self::save( $state );
		wp_safe_redirect( self::settingsUrl() );
		exit;
	}

	/** After activation: a notice that leads to the checklist, until Settings has been opened. */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( 'manage_options' ) || self::state()['seen'] || ( $screen && in_array( $screen->id, array( 'update', 'plugin-install' ), true ) ) ) {
			return;
		}
		if ( $screen && str_ends_with( (string) $screen->id, '_page_' . SettingsPage::SLUG ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Pulse Question is active.', 'pulse-question' ) . '</strong> '
			. esc_html__( 'A short checklist walks you through setting it up.', 'pulse-question' )
			. ' <a class="button button-primary" style="margin-left:6px" href="' . esc_url( self::settingsUrl() ) . '">' . esc_html__( 'Set up Pulse Question', 'pulse-question' ) . '</a></p></div>';
	}
}
