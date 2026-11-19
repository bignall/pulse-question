<?php
/**
 * Your logo and colours, used on share images, in the daily email and (when set) on the site.
 *
 * Logo (setting brand_logo): 0 = automatic (the site's logo from the Customizer or Site
 * Editor, otherwise the site icon, otherwise none), -1 = the Pulse Question logo that comes
 * with the plugin, or a Media Library image id.
 *
 * Colours (brand_accent, brand_accent_2): empty = the theme's Automatic.css colours on the
 * site and the plugin's defaults elsewhere. Set, they're used everywhere.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

final class Branding {

	public const DEFAULT_ACCENT   = '#fb3f03';
	public const DEFAULT_ACCENT_2 = '#03c0b8';

	/** The main colour: buttons, links, the address on share images. */
	public static function accent(): string {
		return self::hex( (string) Settings::get( 'brand_accent' ) ) ?? self::DEFAULT_ACCENT;
	}

	/** The second colour: labels and the pulse line. */
	public static function accent2(): string {
		return self::hex( (string) Settings::get( 'brand_accent_2' ) ) ?? self::DEFAULT_ACCENT_2;
	}

	public static function hasCustomColors(): bool {
		return null !== self::hex( (string) Settings::get( 'brand_accent' ) ) || null !== self::hex( (string) Settings::get( 'brand_accent_2' ) );
	}

	/** The plugin's CSS custom properties for the colours you set (empty when none are set). */
	public static function inlineCss(): string {
		$vars = array();
		if ( null !== self::hex( (string) Settings::get( 'brand_accent' ) ) ) {
			$vars[] = '--pq-accent:' . self::accent();
		}
		if ( null !== self::hex( (string) Settings::get( 'brand_accent_2' ) ) ) {
			$vars[] = '--pq-accent-2:' . self::accent2();
		}
		return $vars ? '.pq-today,.pq-past,.pq-asked,.pq-cats,.pq-answers,.pq-share,.pq-template{' . implode( ';', $vars ) . '}' : '';
	}

	/** Path of the logo image file for share images, or '' for none. */
	public static function logoPath(): string {
		$choice = (int) Settings::get( 'brand_logo' );
		if ( -1 === $choice ) {
			return self::bundledLogo();
		}
		if ( $choice > 0 ) {
			return self::file( $choice );
		}
		// Automatic: the site's logo, then the site icon.
		$custom = (int) get_theme_mod( 'custom_logo' );
		if ( $custom > 0 && '' !== self::file( $custom ) ) {
			return self::file( $custom );
		}
		$icon = (int) get_option( 'site_icon' );
		return $icon > 0 ? self::file( $icon ) : '';
	}

	/** Where the automatic logo comes from: logo, icon or none. */
	public static function automaticSource(): string {
		$custom = (int) get_theme_mod( 'custom_logo' );
		if ( $custom > 0 && '' !== self::file( $custom ) ) {
			return 'logo';
		}
		$icon = (int) get_option( 'site_icon' );
		return $icon > 0 && '' !== self::file( $icon ) ? 'icon' : 'none';
	}

	public static function bundledLogo(): string {
		return dirname( __DIR__ ) . '/assets/img/logo.png';
	}

	private static function file( int $attachment ): string {
		$path = (string) get_attached_file( $attachment );
		return '' !== $path && is_readable( $path ) ? $path : '';
	}

	/** A #rrggbb colour, or null. */
	public static function hex( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			$value = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : null;
	}

	/** Mix a colour with black: 0 = unchanged, 1 = black. */
	public static function darken( string $hex, float $amount ): string {
		$hex = self::hex( $hex ) ?? '#000000';
		$out = '#';
		for ( $i = 1; $i < 7; $i += 2 ) {
			$out .= sprintf( '%02x', (int) round( hexdec( substr( $hex, $i, 2 ) ) * ( 1 - $amount ) ) );
		}
		return $out;
	}
}
