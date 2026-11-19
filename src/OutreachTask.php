<?php
/**
 * Lifecycle for the daily email and social posts: deactivating clears their scheduled events;
 * uninstalling (with "delete all data" on) removes the share images.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

use PulseQuestion\Vendor\Bignall\WPFoundation\Plugin;
use PulseQuestion\Vendor\Bignall\WPFoundation\Tasks\Task;

final class OutreachTask implements Task {

	public function register( Plugin $plugin ): void {}

	public function activate( Plugin $plugin ): void {}

	public function deactivate( Plugin $plugin ): void {
		foreach ( array( Email\DailyEmail::HOOK, Email\Builtin::BATCH_HOOK, Social\Webhook::HOOK ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public function upgrade( Plugin $plugin, string $from, string $to ): void {}

	public function uninstall( Plugin $plugin ): void {
		$uploads = wp_upload_dir( null, false );
		$dir     = $uploads['basedir'] . '/pulse-question';
		if ( empty( $uploads['error'] ) && is_dir( $dir ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			global $wp_filesystem;
			if ( $wp_filesystem ) {
				$wp_filesystem->delete( $dir, true );
			}
		}
		delete_option( 'pq_email_job' );
		delete_option( 'pq_email_lock' );
	}
}
