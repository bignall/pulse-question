<?php
/**
 * Text helpers.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

final class Text {

	/**
	 * Plain text from WordPress's display text: titles and the site name come with HTML entities
	 * (wptexturize turns ' into &#8217;), which emails, share links and social posts would show as is.
	 */
	public static function plain( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
