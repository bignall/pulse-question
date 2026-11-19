<?php
/**
 * Questions → Settings, and the button that makes a page showing today's question and past ones.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

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
