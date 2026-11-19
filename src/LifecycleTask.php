<?php
/**
 * Lifecycle extras: deactivating clears the daily email and social posts' scheduled events;
 * upgrading from before 0.6.0 keeps the Pulse Question logo on share images (new sites use their
 * own); uninstalling (with "delete all data" on) removes the share images and members' Pulse
 * Question details.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

use PulseQuestion\Vendor\Bignall\WPFoundation\Plugin;
use PulseQuestion\Vendor\Bignall\WPFoundation\Tasks\Task;

final class LifecycleTask implements Task {

	public function register( Plugin $plugin ): void {}

	public function activate( Plugin $plugin ): void {}

	public function deactivate( Plugin $plugin ): void {
		foreach ( array( Email\DailyEmail::HOOK, Email\Builtin::BATCH_HOOK, Social\Webhook::HOOK ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public function upgrade( Plugin $plugin, string $from, string $to ): void {
		// Share images used the bundled logo before 0.6.0; keep it until the owner chooses another.
		if ( '' !== $from && version_compare( $from, '0.6.0', '<' ) && 0 === (int) Settings::get( 'brand_logo' ) ) {
			Settings::store()->update( array( 'brand_logo' => -1 ) );
		}
	}

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
		// Members stay (they're WordPress users); their Pulse Question details go.
		foreach ( array( Members\Members::META_JOINED, Members\Members::META_MARKETING, Members\Members::META_ASKED, Members\MailingList::META_PENDING ) as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		delete_option( 'pq_email_job' );
		delete_option( 'pq_email_lock' );
	}
}
