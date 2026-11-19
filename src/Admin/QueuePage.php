<?php
/**
 * Questions → Queue: today's question, adding questions (one per line), approving waiting ones,
 * and the queue in order, with the day each will be asked. Drag to reorder.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

use PulseQuestion\App;
use PulseQuestion\Daily;
use PulseQuestion\Front\Render;
use PulseQuestion\Questions;
use PulseQuestion\Settings;
use PulseQuestion\Vendor\Bignall\WPFoundation\Plugin;

final class QueuePage {

	public const SLUG = 'pq-queue';

	private const ACTIONS = array( 'pq_add', 'pq_approve', 'pq_approve_all', 'pq_reorder', 'pq_ask_now', 'pq_trash' );

	public static function init( Plugin $plugin ): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 9 );
		add_action( 'admin_menu', array( self::class, 'menuOrder' ), 99 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
		add_filter( 'plugin_action_links_' . $plugin->basename(), array( self::class, 'pluginLinks' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=' . self::SLUG ) );
	}

	public static function menu(): void {
		$waiting = count( Questions::waiting() );
		$bubble  = $waiting ? ' <span class="awaiting-mod"><span class="pending-count">' . number_format_i18n( $waiting ) . '</span></span>' : '';
		add_submenu_page( 'edit.php?post_type=' . Questions::POST_TYPE, __( 'Question Queue', 'pulse-question' ), __( 'Queue', 'pulse-question' ) . $bubble, Questions::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	/** Put Queue first under Questions. */
	public static function menuOrder(): void {
		global $submenu;
		$parent = 'edit.php?post_type=' . Questions::POST_TYPE;
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		foreach ( $submenu[ $parent ] as $i => $item ) {
			if ( self::SLUG === ( $item[2] ?? '' ) ) {
				unset( $submenu[ $parent ][ $i ] );
				array_unshift( $submenu[ $parent ], $item );
				break;
			}
		}
	}

	/**
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function pluginLinks( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Queue', 'pulse-question' ) . '</a>' );
		return $links;
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || Questions::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'pq-admin', App::url( 'assets/admin.css' ), array(), App::version() );
		if ( str_ends_with( $hook, self::SLUG ) ) {
			wp_enqueue_script( 'pq-queue', App::url( 'assets/queue.js' ), array( 'jquery', 'jquery-ui-sortable' ), App::version(), true );
		}
	}

	private static function actionUrl( string $action, int $id = 0 ): string {
		$args = array( 'action' => $action );
		if ( $id ) {
			$args['question'] = $id;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action . ( $id ? '_' . $id : '' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Questions::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage questions.', 'pulse-question' ) );
		}
		$today   = Daily::current();
		$queue   = Questions::queue();
		$waiting = Questions::waiting();
		$warn    = (int) Settings::get( 'low_queue_days' );
		?>
		<div class="wrap pq-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Question Queue', 'pulse-question' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Questions::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add Question with details', 'pulse-question' ); ?></a>
			<hr class="wp-header-end" />
			<?php self::notice(); ?>

			<div class="pq-panel pq-panel--today">
				<h2><?php esc_html_e( 'Today', 'pulse-question' ); ?></h2>
				<?php if ( null === $today ) : ?>
					<p><?php esc_html_e( 'No question yet. Add some below; the first one is asked straight away.', 'pulse-question' ); ?></p>
				<?php else : ?>
					<p class="pq-panel__question"><a href="<?php echo esc_url( (string) get_permalink( $today['id'] ) ); ?>"><?php echo esc_html( get_the_title( $today['id'] ) ); ?></a></p>
					<p class="description">
						<?php
						echo esc_html( Render::dayLabel( $today['day'] ) );
						if ( $today['reused'] ) {
							echo ' · ' . esc_html__( 'asked again, because the queue was empty', 'pulse-question' );
						}
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( self::actionUrl( 'pq_ask_now' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Replace today\'s question with the next one in the queue?', 'pulse-question' ) ); ?>');"><?php esc_html_e( 'Ask the next one now', 'pulse-question' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="pq-panel">
				<h2><?php esc_html_e( 'Add questions', 'pulse-question' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pq_add" />
					<?php wp_nonce_field( 'pq_add' ); ?>
					<label class="screen-reader-text" for="pq-new"><?php esc_html_e( 'Questions, one per line', 'pulse-question' ); ?></label>
					<textarea id="pq-new" name="questions" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'One question per line', 'pulse-question' ); ?>"></textarea>
					<p>
						<?php submit_button( __( 'Add to the queue', 'pulse-question' ), 'primary', 'submit', false ); ?>
						<span class="description">
							<?php
							echo esc_html(
								Settings::get( 'approve_mine' )
									? __( 'They go straight into the queue, at the end.', 'pulse-question' )
									: __( 'They wait for approval first (see Settings).', 'pulse-question' )
							);
							?>
						</span>
					</p>
				</form>
			</div>

			<?php if ( $waiting ) : ?>
				<div class="pq-panel">
					<h2>
						<?php
						/* translators: %s: number of questions. */
						echo esc_html( sprintf( __( 'Waiting for approval (%s)', 'pulse-question' ), number_format_i18n( count( $waiting ) ) ) );
						?>
						<a class="button button-small" href="<?php echo esc_url( self::actionUrl( 'pq_approve_all' ) ); ?>"><?php esc_html_e( 'Approve all', 'pulse-question' ); ?></a>
					</h2>
					<ul class="pq-list">
						<?php foreach ( $waiting as $id ) : ?>
							<li class="pq-row">
								<span class="pq-row__title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
								<span class="pq-row__actions">
									<a class="button button-small button-primary" href="<?php echo esc_url( self::actionUrl( 'pq_approve', $id ) ); ?>"><?php esc_html_e( 'Approve', 'pulse-question' ); ?></a>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'pulse-question' ); ?></a>
									<a class="pq-delete" href="<?php echo esc_url( self::actionUrl( 'pq_trash', $id ) ); ?>"><?php esc_html_e( 'Reject', 'pulse-question' ); ?></a>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="pq-panel">
				<h2>
					<?php
					/* translators: %s: number of questions. */
					echo esc_html( sprintf( _n( 'Up next: %s question', 'Up next: %s questions', count( $queue ), 'pulse-question' ), number_format_i18n( count( $queue ) ) ) );
					?>
				</h2>
				<?php if ( $warn > 0 && count( $queue ) < $warn ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php
						echo esc_html(
							$queue
								/* translators: %s: number of days. */
								? sprintf( _n( 'Only %s day of questions left.', 'Only %s days of questions left.', count( $queue ), 'pulse-question' ), number_format_i18n( count( $queue ) ) )
								: __( 'The queue is empty, so questions already asked will be asked again, longest ago first.', 'pulse-question' )
						);
						?>
					</p></div>
				<?php endif; ?>
				<?php if ( $queue ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pq_reorder" />
						<?php wp_nonce_field( 'pq_reorder' ); ?>
						<p class="description"><?php esc_html_e( 'Drag to change the order, then save.', 'pulse-question' ); ?></p>
						<ol class="pq-list pq-queue">
							<?php
							$day = null !== $today ? $today['day'] : Daily::day();
							foreach ( $queue as $i => $id ) :
								$when = ( new \DateTimeImmutable( $day, wp_timezone() ) )->modify( '+' . ( $i + ( null !== $today ? 1 : 0 ) ) . ' days' )->format( 'Y-m-d' );
								?>
								<li class="pq-row" data-id="<?php echo esc_attr( (string) $id ); ?>">
									<input type="hidden" name="order[]" value="<?php echo esc_attr( (string) $id ); ?>" />
									<span class="pq-row__handle dashicons dashicons-menu" aria-hidden="true"></span>
									<span class="pq-row__day"><?php echo esc_html( wp_date( 'D, M j', ( new \DateTimeImmutable( $when . ' 12:00', wp_timezone() ) )->getTimestamp() ) ); ?></span>
									<span class="pq-row__title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
									<span class="pq-row__actions">
										<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'pulse-question' ); ?></a>
										<a href="<?php echo esc_url( self::actionUrl( 'pq_ask_now', $id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Make this today\'s question now?', 'pulse-question' ) ); ?>');"><?php esc_html_e( 'Ask now', 'pulse-question' ); ?></a>
									</span>
								</li>
							<?php endforeach; ?>
						</ol>
						<?php submit_button( __( 'Save order', 'pulse-question' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$msg   = isset( $_GET['pq_msg'] ) ? sanitize_key( $_GET['pq_msg'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$count = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		$texts = array(
			/* translators: %s: number of questions. */
			'added'    => sprintf( _n( '%s question added.', '%s questions added.', $count, 'pulse-question' ), number_format_i18n( $count ) ),
			/* translators: %s: number of questions. */
			'approved' => sprintf( _n( '%s question approved.', '%s questions approved.', $count, 'pulse-question' ), number_format_i18n( $count ) ),
			'reordered' => __( 'Queue order saved.', 'pulse-question' ),
			'asked'    => __( 'Today\'s question changed.', 'pulse-question' ),
			'trashed'  => __( 'Question moved to the trash.', 'pulse-question' ),
			'none'     => __( 'Nothing to do.', 'pulse-question' ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texts[ $msg ] ) . '</p></div>';
		}
	}

	public static function handle(): void {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		$id     = isset( $_REQUEST['question'] ) ? absint( $_REQUEST['question'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( Questions::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'pulse-question' ), 403 );
		}
		check_admin_referer( $action . ( $id ? '_' . $id : '' ) );

		$args = array( 'pq_msg' => 'none' );
		switch ( $action ) {
			case 'pq_add':
				$lines  = preg_split( '/\R/', (string) wp_unslash( $_POST['questions'] ?? '' ) ) ?: array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per line in Questions::add().
				$status = Settings::get( 'approve_mine' ) ? 'approved' : 'pending';
				$n      = 0;
				foreach ( $lines as $line ) {
					if ( '' !== trim( $line ) && ! is_wp_error( Questions::add( $line, $status ) ) ) {
						++$n;
					}
				}
				$args = array(
					'pq_msg' => $n ? 'added' : 'none',
					'n'      => $n,
				);
				break;
			case 'pq_approve':
				$args = array(
					'pq_msg' => Questions::approve( $id ) ? 'approved' : 'none',
					'n'      => 1,
				);
				break;
			case 'pq_approve_all':
				$n = 0;
				foreach ( Questions::waiting() as $wid ) {
					$n += Questions::approve( $wid ) ? 1 : 0;
				}
				$args = array(
					'pq_msg' => 'approved',
					'n'      => $n,
				);
				break;
			case 'pq_reorder':
				Questions::reorder( array_map( 'absint', (array) wp_unslash( $_POST['order'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint.
				$args = array( 'pq_msg' => 'reordered' );
				break;
			case 'pq_ask_now':
				$args = array( 'pq_msg' => null !== Daily::askNow( $id ) ? 'asked' : 'none' );
				break;
			case 'pq_trash':
				$args = array( 'pq_msg' => $id && get_post_type( $id ) === Questions::POST_TYPE && wp_trash_post( $id ) ? 'trashed' : 'none' );
				break;
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
