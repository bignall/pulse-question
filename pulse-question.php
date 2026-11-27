<?php
/**
 * Plugin Name:       Pulse Question
 * Plugin URI:        https://bignall.github.io/pulse-question/
 * Description:       A question of the day for WordPress: a queue you control, member answers checked by Claude, a daily email and share images.
 * Version:           0.7.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            RB Creative Solutions
 * Author URI:        https://rbcreativesolutions.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pulse-question
 *
 * @package PulseQuestion
 */

defined( 'ABSPATH' ) || exit;

// Keep this file PHP 5.6-compatible so old hosts get a notice instead of a parse error.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Pulse Question requires PHP 8.1 or newer.', 'pulse-question' ) . '</p></div>';
		}
	);
	return;
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Pulse Question is missing its dependencies. Run "composer install" in the plugin folder, or install a release zip.', 'pulse-question' ) . '</p></div>';
		}
	);
	return;
}

if ( file_exists( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';
}
require_once __DIR__ . '/vendor/autoload.php';

define( 'PULSE_QUESTION_FILE', __FILE__ );

PulseQuestion\Bootstrap::plugin( __FILE__ )->boot();
