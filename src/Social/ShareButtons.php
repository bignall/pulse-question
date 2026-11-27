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
		$html .= '<span class="pq-share__label">' . esc_html__( 'Share', 'pulse-question' ) . '</span>';
		$html .= '<button type="button" class="pq-share__link pq-share__native" data-pq-share-native hidden>' . esc_html__( 'Share…', 'pulse-question' ) . '</button>';
		foreach ( $links as $key => [ $label, $href ] ) {
			$external = ! str_starts_with( (string) $href, 'mailto:' );
			$html    .= '<a class="pq-share__link pq-share__link--' . esc_attr( (string) $key ) . '" href="' . esc_url( (string) $href, array( 'https', 'mailto' ) ) . '"'
				. ( $external ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( (string) $label )
				. ( $external ? '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'pulse-question' ) . '</span>' : '' ) . '</a>';
		}
		$html .= '<button type="button" class="pq-share__link" data-pq-copy data-copied="' . esc_attr__( 'Link copied', 'pulse-question' ) . '">' . esc_html__( 'Copy link', 'pulse-question' ) . '</button>';
		$html .= '<span class="pq-share__copied" role="status" aria-live="polite"></span>';
		$html .= '</div>';
		return $html;
	}
}
