<?php
/**
 * Share links under each question: Facebook, X, LinkedIn, Pinterest (with the share image),
 * Bluesky, email and copy link, plus the phone's own share sheet where the browser has one.
 *
 * Plain links to each network's share page: no third-party scripts, nothing loaded from them
 * and no tracking until someone clicks.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Social;

use PulseQuestion\App;
use PulseQuestion\Settings;

final class ShareButtons {

	public static function render( int $post_id ): string {
		if ( ! Settings::get( 'share_buttons' ) ) {
			return '';
		}
		$url   = (string) get_permalink( $post_id );
		$title = \PulseQuestion\Text::plain( get_the_title( $post_id ) );
		$text  = trim( (string) preg_replace( '/\s+/', ' ', $title ) );
		$image = ShareImage::forQuestion( $post_id );

		$links = array(
			'facebook'  => array( 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) ),
			'x'         => array( 'X', 'https://x.com/intent/post?text=' . rawurlencode( $text ) . '&url=' . rawurlencode( $url ) ),
			'linkedin'  => array( 'LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url ) ),
			'pinterest' => array( 'Pinterest', 'https://pinterest.com/pin/create/button/?url=' . rawurlencode( $url ) . ( $image ? '&media=' . rawurlencode( $image['url'] ) : '' ) . '&description=' . rawurlencode( $text ) ),
			'bluesky'   => array( 'Bluesky', 'https://bsky.app/intent/compose?text=' . rawurlencode( $text . ' ' . $url ) ),
			'email'     => array( __( 'Email', 'pulse-question' ), 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $text . "\n\n" . $url ) ),
		);
		/**
		 * The share links: key => array( label, url ).
		 *
		 * @param array<string, array{0: string, 1: string}> $links   Links.
		 * @param int                                        $post_id The question.
		 */
		$links = (array) apply_filters( 'pulse_question_share_links', $links, $post_id );

		wp_enqueue_script( 'pulse-question-share', App::url( 'assets/share.js' ), array(), App::version(), true );

		$html  = '<div class="pq-share" data-pq-share-url="' . esc_attr( $url ) . '" data-pq-share-title="' . esc_attr( $title ) . '">';
		$html .= '<span class="pq-share__label">' . esc_html__( 'Share this question', 'pulse-question' ) . '</span>';
		$html .= self::button( 'pq-share__native', __( 'Share…', 'pulse-question' ), 'share', ' data-pq-share-native hidden' );
		foreach ( $links as $key => [ $label, $href ] ) {
			$key      = (string) $key;
			$label    = (string) $label;
			$external = ! str_starts_with( (string) $href, 'mailto:' );
			$icon     = self::icon( $key );
			$name     = $external
				/* translators: %s: a network, such as Facebook. */
				? sprintf( __( 'Share on %s (opens in a new tab)', 'pulse-question' ), $label )
				: __( 'Share by email', 'pulse-question' );
			// Known networks get an icon with the name as hover text; others keep their text label.
			$html .= '<a class="pq-share__link pq-share__link--' . esc_attr( $key ) . ( '' !== $icon ? ' pq-share__link--icon' : '' ) . '" href="' . esc_url( (string) $href, array( 'https', 'mailto' ) ) . '"'
				. ( $external ? ' target="_blank" rel="noopener noreferrer"' : '' )
				. ( '' !== $icon ? ' aria-label="' . esc_attr( $name ) . '" data-pq-tip="' . esc_attr( $label ) . '">' . $icon : '>' . esc_html( $label ) )
				. '</a>';
		}
		$html .= self::button( 'pq-share__copy', __( 'Copy link', 'pulse-question' ), 'link', ' data-pq-copy data-copied="' . esc_attr__( 'Link copied', 'pulse-question' ) . '"' );
		$html .= '<span class="pq-share__copied" role="status" aria-live="polite"></span>';
		$html .= '</div>';
		return $html;
	}

	/** An icon button (copy link, the phone's share sheet) with its name as hover text. */
	private static function button( string $class, string $label, string $icon, string $attrs ): string {
		return '<button type="button" class="pq-share__link pq-share__link--icon ' . esc_attr( $class ) . '" aria-label="' . esc_attr( $label ) . '" data-pq-tip="' . esc_attr( $label ) . '"' . $attrs . '>' . self::icon( $icon ) . '</button>';
	}

	/** A 24px icon for a network or action, or '' when there isn't one. */
	public static function icon( string $key ): string {
		$fill   = array(
			'facebook'  => 'M14 8h3V4h-3c-2.8 0-4 1.7-4 4.3V10H7v4h3v8h4v-8h3l1-4h-4V8.6c0-.4.3-.6.6-.6z',
			'x'         => 'M4 4l6.6 8.8L4.3 20h1.9l5.3-6.1L15.8 20H20l-6.9-9.2L19 4h-1.9l-4.9 5.6L8.2 4zm2.4 1.3h1.2l9.9 13.4h-1.2z',
			'linkedin'  => 'M6.5 8.8H3.6V20h2.9zM5 3.6a1.7 1.7 0 1 0 0 3.4 1.7 1.7 0 0 0 0-3.4zM20.4 13.6c0-3-1.6-4.9-4.2-4.9-1.4 0-2.4.8-2.8 1.5V8.8h-2.8V20h2.9v-5.6c0-1.5.3-2.9 2.1-2.9s1.9 1.7 1.9 3V20h2.9z',
			'pinterest' => 'M12 2a10 10 0 0 0-3.6 19.3c-.1-.8-.2-2 0-2.9l1.2-5s-.3-.6-.3-1.5c0-1.4.8-2.4 1.8-2.4.9 0 1.3.6 1.3 1.4 0 .9-.6 2.1-.8 3.3-.2 1 .5 1.8 1.5 1.8 1.8 0 3.1-1.9 3.1-4.6 0-2.4-1.7-4.1-4.2-4.1-2.9 0-4.5 2.2-4.5 4.4 0 .9.3 1.8.8 2.3.1.1.1.2.1.3l-.3 1.2c0 .2-.2.2-.4.1-1.3-.6-2.1-2.5-2.1-4 0-3.3 2.4-6.3 6.9-6.3 3.6 0 6.4 2.6 6.4 6 0 3.6-2.3 6.5-5.4 6.5-1.1 0-2.1-.6-2.4-1.2l-.7 2.5c-.2.9-.9 2.1-1.3 2.8A10 10 0 1 0 12 2z',
			'bluesky'   => 'M6.3 4.6C8.6 6.3 11 9.8 12 11.8c1-2 3.4-5.5 5.7-7.2 1.6-1.2 4.3-2.2 4.3.9 0 .6-.4 5.2-.6 5.9-.7 2.6-3.3 3.2-5.6 2.8 4 .7 5 2.9 2.8 5.2-4.2 4.3-6-1.1-6.5-2.5l-.1-.3-.1.3c-.5 1.4-2.3 6.8-6.5 2.5-2.2-2.3-1.2-4.5 2.8-5.2-2.3.4-4.9-.2-5.6-2.8C2.4 10.7 2 6.1 2 5.5c0-3.1 2.7-2.1 4.3-.9z',
		);
		$stroke = array(
			'email' => 'M3 6h18v12H3z M3 7l9 6 9-6',
			'link'  => 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7 M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
			'share' => 'M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7 M12 3v12 M7 8l5-5 5 5',
		);
		$open = '<svg class="pq-share__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">';
		if ( isset( $fill[ $key ] ) ) {
			return $open . '<path fill="currentColor" d="' . $fill[ $key ] . '"/></svg>';
		}
		if ( isset( $stroke[ $key ] ) ) {
			return $open . '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="' . $stroke[ $key ] . '"/></svg>';
		}
		return '';
	}
}
