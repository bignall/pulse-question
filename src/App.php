<?php
/**
 * Wires the plugin's runtime services once WP Foundation says it's ready.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

use PulseQuestion\Vendor\Bignall\WPFoundation\Plugin;

final class App {

	private static ?Plugin $plugin = null;

	public static function boot( Plugin $plugin ): void {
		self::$plugin = $plugin;

		load_plugin_textdomain( 'pulse-question', false, dirname( $plugin->basename() ) . '/languages' );

		Questions::init();
		Daily::init();
		Todo::init();
		Claude\TopUp::init();
		Front\Render::init();
		Front\Shortcodes::init();
		Front\Blocks::init();
		Front\Templates::init();
		Front\QueryViews::init();

		if ( is_admin() ) {
			Admin\QueuePage::init( $plugin );
			Admin\QuestionEditor::init();
			Admin\SettingsPage::init();
		}
	}

	public static function url( string $path = '' ): string {
		return plugins_url( $path, PQ_FILE );
	}

	public static function version(): string {
		return null !== self::$plugin ? self::$plugin->currentVersion() : '0';
	}
}
