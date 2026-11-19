<?php
/**
 * Optional link-up with To-do Hub: a task when the queue is running low.
 *
 * "Add questions to Pulse Question: N days left" when fewer approved questions are waiting than
 * the warning level in Settings (high priority once it's down to reusing old questions), and
 * "Approve N questions" while questions are waiting for approval. Marked done when that's no
 * longer true. Checked whenever a question changes status and with the hourly check.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

final class Todo {

	public const SOURCE = 'pulse-question';

	/** What was last sent: 'low' => days left or -1, 'waiting' => count. */
	public const OPTION = 'pq_todo_sent';

	private static bool $dirty = false;

	public static function init(): void {
		add_action( 'transition_post_status', array( self::class, 'onTransition' ), 10, 3 );
		add_action( 'pulse_question_new_day', array( self::class, 'markDirty' ) );
		add_action( Daily::CRON_HOOK, array( self::class, 'check' ), 20 );
		add_action( 'shutdown', array( self::class, 'flush' ) );
	}

	public static function enabled(): bool {
		return function_exists( 'todo_hub_add' ) && (bool) Settings::get( 'todo_tasks' );
	}

	public static function onTransition( string $new, string $old, \WP_Post $post ): void {
		if ( Questions::POST_TYPE === $post->post_type && $new !== $old ) {
			self::$dirty = true;
		}
	}

	public static function markDirty(): void {
		self::$dirty = true;
	}

	public static function flush(): void {
		if ( self::$dirty ) {
			self::$dirty = false;
			self::check();
		}
	}

	public static function check(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$sent = get_option( self::OPTION, array() );
		$sent = is_array( $sent ) ? $sent : array();

		// Queue running low. -1 = fine.
		$left = Daily::daysLeft();
		$warn = (int) Settings::get( 'low_queue_days' );
		$low  = ( $warn > 0 && $left < $warn ) ? $left : -1;
		if ( $low !== ( $sent['low'] ?? -1 ) ) {
			if ( -1 === $low ) {
				todo_hub_complete( self::SOURCE . ':low-queue' );
			} else {
				todo_hub_add(
					array(
						'title'    => 0 === $low
							? __( 'Add questions to Pulse Question: the queue is empty, old questions are being asked again', 'pulse-question' )
							/* translators: %s: number of days. */
							: sprintf( _n( 'Add questions to Pulse Question: %s day left', 'Add questions to Pulse Question: %s days left', $low, 'pulse-question' ), number_format_i18n( $low ) ),
						'category' => 'Pulse Question',
						'priority' => $low <= 2 ? 'high' : 'normal',
						'source'   => self::SOURCE,
						'key'      => self::SOURCE . ':low-queue',
						'link'     => admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=pq-queue' ),
					)
				);
			}
			$sent['low'] = $low;
		}

		// Waiting for approval.
		$waiting = count( Questions::waiting() );
		if ( $waiting !== (int) ( $sent['waiting'] ?? 0 ) ) {
			if ( 0 === $waiting ) {
				todo_hub_complete( self::SOURCE . ':approve' );
			} else {
				todo_hub_add(
					array(
						/* translators: %s: number of questions. */
						'title'    => sprintf( _n( 'Approve %s Pulse Question question', 'Approve %s Pulse Question questions', $waiting, 'pulse-question' ), number_format_i18n( $waiting ) ),
						'category' => 'Pulse Question',
						'source'   => self::SOURCE,
						'key'      => self::SOURCE . ':approve',
						'link'     => admin_url( 'edit.php?post_type=' . Questions::POST_TYPE . '&page=pq-queue' ),
					)
				);
			}
			$sent['waiting'] = $waiting;
		}

		update_option( self::OPTION, $sent, false );
	}
}
