<?php
/**
 * Moves a site's data from the names used before 0.6.0 (prefix pq_) to the current ones
 * (pulse_question_), as wordpress.org asks plugins to use prefixes of at least four letters.
 *
 * Runs once per site, the first time 0.6.0 loads there, before WP Foundation starts (it keeps
 * its own version in a prefixed option, so that has to move first or the site would look like a
 * new install). Each step only moves what's still under the old name, so a second run does
 * nothing and an interrupted run finishes on the next request.
 *
 *   options        pq_settings → pulse_question_settings, and the other options and logs
 *   meta           _pq_asked → _pulse_question_asked, and the other post, comment and user meta
 *   content        post type pq_question → pulse_question, status pq_approved → pulseq_approved
 *                  (statuses are limited to 20 characters), taxonomy pq_category →
 *                  pulse_question_category, saved block templates and menu items
 *   table          {prefix}pq_votes → {prefix}pulse_question_votes
 *   scheduled      events moved to the new hook names, keeping their times and arguments
 *
 * Old query arguments (post_type pq_question, pq_view, pq_category) keep working: see
 * Front\QueryViews::oldNames().
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

final class PrefixMigration {

	private const LOCK = 'pulse_question_migrating';

	/** Options: old => new. */
	private const OPTIONS = array(
		'pq_settings'               => 'pulse_question_settings',
		'pq_today'                  => 'pulse_question_today',
		'pq_claude_log'             => 'pulse_question_claude_log',
		'pq_claude_wait'            => 'pulse_question_claude_wait',
		'pq_claude_failures'        => 'pulse_question_claude_failures',
		'pq_list_log'               => 'pulse_question_list_log',
		'pq_email_state'            => 'pulse_question_email_state',
		'pq_email_log'              => 'pulse_question_email_log',
		'pq_email_job'              => 'pulse_question_email_job',
		'pq_social_state'           => 'pulse_question_social_state',
		'pq_social_log'             => 'pulse_question_social_log',
		'pq_social_secret'          => 'pulse_question_social_secret',
		'pq_todo_sent'              => 'pulse_question_todo_sent',
		'pq_setup'                  => 'pulse_question_setup',
		// WP Foundation's own.
		'pq_installed_at'           => 'pulse_question_installed_at',
		'pq_upgrade_error'          => 'pulse_question_upgrade_error',
		'pq_deactivation_feedback'  => 'pulse_question_deactivation_feedback',
		'pq_flush_rewrite_rules'    => 'pulse_question_flush_rewrite_rules',
		// Last: its presence is what starts this migration.
		'pq_version'                => 'pulse_question_version',
	);

	/** Locks that are only ever held for seconds: dropped rather than moved. */
	private const DROP = array( 'pq_pick_lock', 'pq_email_lock', 'pq_upgrade_lock', 'pq_category_children' );

	private const META = array(
		'postmeta'    => array( '_pq_asked', '_pq_last_asked', '_pq_source', '_pq_votes' ),
		'commentmeta' => array( '_pq_votes', '_pq_check', '_pq_reports', '_pq_hidden_by_reports' ),
		'usermeta'    => array( '_pq_joined', '_pq_marketing', '_pq_join_asked', '_pq_list_pending' ),
	);

	private const CRON = array( 'pq_daily_check', 'pq_recheck_answer', 'pq_daily_email', 'pq_email_batch', 'pq_social_post' );

	/** Run if this site still has data under the old names. */
	public static function maybeRun(): void {
		if ( false === get_option( 'pq_version' ) && false === get_option( 'pq_settings' ) ) {
			return;
		}
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			// Another request is migrating; a lock older than five minutes is from a run that died.
			if ( (int) get_option( self::LOCK ) > time() - 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( self::LOCK, time(), false );
		}
		try {
			self::run();
		} finally {
			delete_option( self::LOCK );
		}
	}

	public static function run(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a one-time rename of the plugin's own data.

		// Content first, while the old version option still marks the migration as unfinished.
		$wpdb->update( $wpdb->posts, array( 'post_type' => 'pulse_question' ), array( 'post_type' => 'pq_question' ) );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'pulseq_approved' ), array( 'post_status' => 'pq_approved' ) );
		$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => 'pulse_question_category' ), array( 'taxonomy' => 'pq_category' ) );
		foreach ( array(
			'single-pq_question' => 'single-pulse_question',
			'archive-pq_question' => 'archive-pulse_question',
			'taxonomy-pq_category' => 'taxonomy-pulse_question_category',
		) as $old => $new ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'post_name' => $new ),
				array(
					'post_name' => $old,
					'post_type' => 'wp_template',
				)
			);
		}
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => 'pulse_question' ),
			array(
				'meta_key' => '_menu_item_object',
				'meta_value' => 'pq_question',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => 'pulse_question_category' ),
			array(
				'meta_key' => '_menu_item_object',
				'meta_value' => 'pq_category',
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery

		foreach ( self::META as $table => $keys ) {
			foreach ( $keys as $old ) {
				$wpdb->update( $wpdb->$table, array( 'meta_key' => '_pulse_question_' . substr( $old, 4 ) ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
		}

		// The votes table: renamed where the database can (MySQL, MariaDB), otherwise copied.
		$old_table = $wpdb->prefix . 'pq_votes';
		$tables    = (array) $wpdb->get_col( 'SHOW TABLES' );
		if ( in_array( $old_table, $tables, true ) ) {
			$votes     = new Answers\VotesTable();
			$new_table = $votes->tableName();
			if ( ! in_array( $new_table, $tables, true ) ) {
				$wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names.
			}
			if ( in_array( $old_table, (array) $wpdb->get_col( 'SHOW TABLES' ), true ) ) {
				$votes->install();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
				$wpdb->query( "INSERT IGNORE INTO {$new_table} (object_type, object_id, user_id, created_at) SELECT object_type, object_id, user_id, created_at FROM {$old_table}" );
				$wpdb->query( "DROP TABLE {$old_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}

		// Scheduled events: same times and arguments, new hook names.
		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $time => $hooks ) {
				foreach ( self::CRON as $old ) {
					if ( isset( $hooks[ $old ] ) ) {
						$new = 'pulse_question_' . substr( $old, 3 );
						// phpcs:ignore WordPress.WP.CronInterval -- moving existing events.
						$cron[ $time ][ $new ] = array_merge( $cron[ $time ][ $new ] ?? array(), $hooks[ $old ] );
						unset( $cron[ $time ][ $old ] );
					}
				}
			}
			_set_cron_array( $cron );
		}

		// Options, the version last.
		foreach ( self::OPTIONS as $old => $new ) {
			self::renameOption( $old, $new );
		}
		$notices = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'pq_notices_' ) . '%' ) );
		foreach ( $notices as $old ) {
			self::renameOption( (string) $old, 'pulse_question_' . substr( (string) $old, 3 ) );
		}
		foreach ( self::DROP as $old ) {
			delete_option( $old );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_pq_' ) . '%', $wpdb->esc_like( '_transient_timeout_pq_' ) . '%' ) );
		// phpcs:enable
		wp_cache_flush();
	}

	private static function renameOption( string $old, string $new ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $old ) );
		if ( ! $exists ) {
			return;
		}
		$taken = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) );
		if ( $taken ) {
			// Already there (an earlier, interrupted run): the old copy goes.
			$wpdb->delete( $wpdb->options, array( 'option_name' => $old ) );
			return;
		}
		$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ) );
		// phpcs:enable
	}
}
