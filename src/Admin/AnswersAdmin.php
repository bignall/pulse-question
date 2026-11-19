<?php
/**
 * The Comments screen for answers: a "Check" column with the automatic moderation's verdict and
 * reason, and how many members reported the answer.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

use PulseQuestion\Answers\Answers;
use PulseQuestion\Answers\Moderator;

final class AnswersAdmin {

	public static function init(): void {
		add_filter( 'manage_edit-comments_columns', array( self::class, 'columns' ) );
		add_action( 'manage_comments_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$columns['pq_check'] = __( 'Check', 'pulse-question' );
		return $columns;
	}

	public static function column( string $column, int|string $comment_id ): void {
		if ( 'pq_check' !== $column ) {
			return;
		}
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment instanceof \WP_Comment || ! Answers::isAnswer( $comment ) ) {
			return;
		}
		$check = get_comment_meta( (int) $comment_id, Moderator::META, true );
		if ( is_array( $check ) && isset( $check['decision'] ) ) {
			$labels = array(
				'allow'  => __( 'Allowed', 'pulse-question' ),
				'review' => __( 'For review', 'pulse-question' ),
				'remove' => __( 'Removed', 'pulse-question' ),
				'error'  => __( 'Not checked yet', 'pulse-question' ),
			);
			echo '<strong>' . esc_html( $labels[ $check['decision'] ] ?? $check['decision'] ) . '</strong>';
			if ( 'none' !== ( $check['category'] ?? 'none' ) ) {
				echo ' <code>' . esc_html( str_replace( '_', ' ', (string) $check['category'] ) ) . '</code>';
			}
			if ( '' !== (string) ( $check['reason'] ?? '' ) ) {
				echo '<br /><span class="description">' . esc_html( (string) $check['reason'] ) . '</span>';
			}
		}
		$reports = count( (array) get_comment_meta( (int) $comment_id, Answers::META_REPORTS ) );
		if ( $reports ) {
			/* translators: %s: number of reports. */
			echo '<br /><span style="color:#b32d2e">' . esc_html( sprintf( _n( 'Reported by %s member', 'Reported by %s members', $reports, 'pulse-question' ), number_format_i18n( $reports ) ) ) . '</span>';
		}
	}
}
