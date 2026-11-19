<?php
/**
 * The question edit screen (classic editor): a Status box (draft, waiting for approval, approved),
 * the days it was asked, and Status and Last asked columns on All Questions.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Admin;

use PulseQuestion\Front\Render;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class QuestionEditor {

	public static function init(): void {
		add_action( 'add_meta_boxes_' . Questions::POST_TYPE, array( self::class, 'addBox' ) );
		add_filter( 'enter_title_here', array( self::class, 'titlePlaceholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( self::class, 'afterTitle' ) );
		add_filter( 'gettext', array( self::class, 'buttonLabel' ), 10, 3 );
		add_filter( 'manage_' . Questions::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Questions::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'views_edit-' . Questions::POST_TYPE, array( self::class, 'views' ) );
	}

	public static function titlePlaceholder( string $text, \WP_Post $post ): string {
		return Questions::POST_TYPE === $post->post_type ? __( 'Ask a question…', 'pulse-question' ) : $text;
	}

	public static function afterTitle( \WP_Post $post ): void {
		if ( Questions::POST_TYPE === $post->post_type ) {
			echo '<p class="description" style="margin:12px 0 4px">' . esc_html__( 'Background (optional): a sentence or two shown under the question.', 'pulse-question' ) . '</p>';
		}
	}

	/** "Publish" and "Update" save the question; the Status box says where it goes. */
	public static function buttonLabel( string $translation, string $text, string $domain ): string {
		if ( 'default' !== $domain || ! in_array( $text, array( 'Publish', 'Update' ), true ) || ! function_exists( 'get_current_screen' ) ) {
			return $translation;
		}
		$screen = get_current_screen();
		return $screen && Questions::POST_TYPE === $screen->post_type && 'post' === $screen->base ? __( 'Save', 'pulse-question' ) : $translation;
	}

	public static function addBox(): void {
		add_meta_box( 'pq-status', __( 'Status', 'pulse-question' ), array( self::class, 'render' ), Questions::POST_TYPE, 'side', 'high' );
	}

	public static function render( \WP_Post $post ): void {
		if ( 'publish' === $post->post_status ) {
			$days = Questions::askedDays( $post->ID );
			echo '<p><strong>' . esc_html__( 'Asked', 'pulse-question' ) . '</strong>: ' . esc_html( implode( ', ', array_map( static fn ( string $d ): string => Render::dayLabel( $d, false ), $days ) ) ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Asked questions stay on the site. Edits show straight away.', 'pulse-question' ) . '</p>';
			return;
		}
		$current = match ( $post->post_status ) {
			Questions::STATUS_APPROVED => 'approved',
			'pending'                  => 'pending',
			'auto-draft'               => Settings::get( 'approve_mine' ) ? 'approved' : 'pending',
			default                    => 'draft',
		};
		$options = array(
			'approved' => __( 'Approved: in the queue', 'pulse-question' ),
			'pending'  => __( 'Waiting for approval', 'pulse-question' ),
			'draft'    => __( 'Draft', 'pulse-question' ),
		);
		echo '<fieldset>';
		foreach ( $options as $value => $label ) {
			echo '<label style="display:block;margin:4px 0"><input type="radio" name="pulse_question_status" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Approved questions are asked in queue order, one a day. Change the order under Questions → Queue.', 'pulse-question' ) . '</p>';
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['pulse_question_status'] = __( 'Status', 'pulse-question' );
				$out['pulse_question_last']   = __( 'Last asked', 'pulse-question' );
				continue;
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $column, int $id ): void {
		if ( 'pulse_question_last' === $column ) {
			echo esc_html( Render::dayLabel( (string) get_post_meta( $id, Questions::META_LAST, true ), false ) );
			return;
		}
		if ( 'pulse_question_status' !== $column ) {
			return;
		}
		$status = get_post_status( $id );
		if ( Questions::STATUS_APPROVED === $status ) {
			$pos = array_search( $id, Questions::queue(), true );
			/* translators: %s: position in the queue. */
			echo esc_html( sprintf( __( 'Approved, #%s in the queue', 'pulse-question' ), number_format_i18n( false === $pos ? 0 : $pos + 1 ) ) );
		} elseif ( 'publish' === $status ) {
			esc_html_e( 'Asked', 'pulse-question' );
		} elseif ( 'pending' === $status ) {
			esc_html_e( 'Waiting for approval', 'pulse-question' );
		} else {
			esc_html_e( 'Draft', 'pulse-question' );
		}
	}

	/**
	 * On All Questions: "Asked" instead of "Published", "Waiting for approval" instead of "Pending".
	 *
	 * @param array<string, string> $views Status links.
	 * @return array<string, string>
	 */
	public static function views( array $views ): array {
		$names = array(
			'publish' => __( 'Asked', 'pulse-question' ),
			'pending' => __( 'Waiting for approval', 'pulse-question' ),
		);
		foreach ( $names as $key => $name ) {
			if ( isset( $views[ $key ] ) ) {
				$views[ $key ] = (string) preg_replace( '/>[^<>]+(<span class="count">)/', '>' . esc_html( $name ) . ' $1', $views[ $key ], 1 );
			}
		}
		return $views;
	}
}
