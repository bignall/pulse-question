<?php
/**
 * A 1200×630 share image for each question (the size Facebook, LinkedIn, X and others show
 * best): the logo, the label, the question in large type and the site's address, on a light
 * background with the pulse line.
 *
 * Made with GD when first needed and kept in uploads/pulse-question/share/. A question's
 * featured image, if it has one, is used instead. The file name carries a hash of the
 * question's text, so an edited question gets a new image.
 *
 * Filters: pulse_question_share_image_colors (array), pulse_question_share_image_logo (path to
 * a PNG), pulse_question_share_image_font (path to a TTF/OTF), pulse_question_share_image
 * (array{url, width, height} or null, the final say).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Social;

use PulseQuestion\Branding;
use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class ShareImage {

	public const WIDTH  = 1200;
	public const HEIGHT = 630;
	/** Instagram's tallest feed shape (4:5). Instagram only takes JPEG. */
	public const PORTRAIT_WIDTH  = 1080;
	public const PORTRAIT_HEIGHT = 1350;
	/** Bump when the design changes, so images are made again. */
	private const DESIGN = 1;

	public static function supported(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' ) && function_exists( 'imagepng' );
	}

	/**
	 * The image for a question: its featured image, or the generated one.
	 *
	 * @return array{url: string, width: int, height: int, path: string}|null
	 */
	public static function forQuestion( int $post_id ): ?array {
		$image = null;
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$src = wp_get_attachment_image_src( $thumb, 'full' );
			if ( $src ) {
				$image = array(
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'path'   => (string) get_attached_file( $thumb ),
				);
			}
		}
		if ( null === $image ) {
			$image = self::generated( $post_id );
		}
		/**
		 * The share image for a question.
		 *
		 * @param array{url: string, width: int, height: int, path: string}|null $image   The image.
		 * @param int                                                            $post_id The question.
		 */
		$image = apply_filters( 'pulse_question_share_image', $image, $post_id );
		return is_array( $image ) && ! empty( $image['url'] ) ? $image : null;
	}

	/**
	 * The portrait JPEG for Instagram (1080x1350), made now if it doesn't exist yet. Always the
	 * generated design, since Instagram needs a JPEG of a shape it accepts.
	 *
	 * @return array{url: string, width: int, height: int, path: string}|null
	 */
	public static function forInstagram( int $post_id ): ?array {
		return function_exists( 'imagejpeg' ) ? self::generated( $post_id, true ) : null;
	}

	/**
	 * The generated image, made now if it doesn't exist yet.
	 *
	 * @param bool $portrait The 1080x1350 JPEG instead of the 1200x630 PNG.
	 * @return array{url: string, width: int, height: int, path: string}|null
	 */
	public static function generated( int $post_id, bool $portrait = false ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || Questions::POST_TYPE !== $post->post_type || ! self::supported() ) {
			return null;
		}
		$dir = self::dir();
		if ( null === $dir ) {
			return null;
		}
		$title = self::cleanText( get_the_title( $post ) );
		$label = self::cleanText( (string) Settings::get( 'label' ) );
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$logo  = self::logo();
		$hash  = substr( md5( self::DESIGN . '|' . $title . '|' . $label . '|' . $host . '|' . wp_json_encode( self::colors() ) . '|' . $logo . '|' . ( '' !== $logo ? (string) @filemtime( $logo ) : '' ) ), 0, 10 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$w     = $portrait ? self::PORTRAIT_WIDTH : self::WIDTH;
		$h     = $portrait ? self::PORTRAIT_HEIGHT : self::HEIGHT;
		$stem  = 'q' . $post_id . ( $portrait ? 'p' : '' ) . '-';
		$ext   = $portrait ? '.jpg' : '.png';
		$name  = $stem . $hash . $ext;
		$path  = $dir['path'] . '/' . $name;
		if ( ! file_exists( $path ) ) {
			foreach ( (array) glob( $dir['path'] . '/' . $stem . '*' . $ext ) as $old ) {
				wp_delete_file( (string) $old );
			}
			if ( ! self::draw( $title, $label, $host, $path, $w, $h, $portrait ) ) {
				return null;
			}
		}
		return array(
			'url'    => $dir['url'] . '/' . $name,
			'width'  => $w,
			'height' => $h,
			'path'   => $path,
		);
	}

	/** Remove a question's generated images (when it's deleted). */
	public static function forget( int $post_id ): void {
		$dir = self::dir( false );
		if ( null !== $dir ) {
			$old = array_merge( (array) glob( $dir['path'] . '/q' . $post_id . '-*.png' ), (array) glob( $dir['path'] . '/q' . $post_id . 'p-*.jpg' ) );
			foreach ( $old as $file ) {
				wp_delete_file( (string) $file );
			}
		}
	}

	/** @return array{path: string, url: string}|null */
	private static function dir( bool $create = true ): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$path = $uploads['basedir'] . '/pulse-question/share';
		if ( $create && ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return null;
		}
		return array(
			'path' => $path,
			'url'  => set_url_scheme( $uploads['baseurl'] . '/pulse-question/share' ),
		);
	}

	/** @return array{background: string, text: string, label: string, accent: string, line: string} */
	public static function colors(): array {
		$colors = array(
			'background' => '#fff8f3',
			'text'       => '#1d1d1f',
			// Darker shades of your colours, so the text reads well on the light background.
			'label'      => Branding::darken( Branding::accent2(), 0.27 ),
			'accent'     => Branding::darken( Branding::accent(), 0.1 ),
			'line'       => Branding::accent2(),
		);
		/**
		 * Share image colours (hex).
		 *
		 * @param array<string, string> $colors background, text, label, accent (the address), line (the pulse).
		 */
		$filtered = apply_filters( 'pulse_question_share_image_colors', $colors );
		return is_array( $filtered ) ? array_merge( $colors, array_filter( $filtered, 'is_string' ) ) : $colors;
	}

	/** The logo file for share images: Settings → Branding, or the filter. */
	private static function logo(): string {
		/** @param string $logo Path to a PNG, JPEG, GIF or WebP logo, or '' for none. */
		return (string) apply_filters( 'pulse_question_share_image_logo', Branding::logoPath() );
	}

	/** Remove what the font can't draw (emoji and other symbols) and tidy the spaces. */
	public static function cleanText( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\x{1F1E6}-\x{1F1FF}]/u', '', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function draw( string $title, string $label, string $host, string $path, int $w = self::WIDTH, int $h = self::HEIGHT, bool $jpeg = false ): bool {
		/** @param string $font Path to a TTF or OTF font. */
		$font = (string) apply_filters( 'pulse_question_share_image_font', dirname( __DIR__, 2 ) . '/assets/fonts/Inter-SemiBold.otf' );
		$logo = self::logo();
		if ( ! is_readable( $font ) ) {
			return false;
		}
		$c   = self::colors();
		$img = imagecreatetruecolor( $w, $h );
		if ( false === $img ) {
			return false;
		}
		imagealphablending( $img, true );
		imagesavealpha( $img, false );
		$col = static fn ( string $hex ): int => (int) imagecolorallocate( $img, ...self::rgb( $hex ) );
		imagefilledrectangle( $img, 0, 0, $w, $h, $col( $c['background'] ) );

		// The portrait image is taller, so everything is a little bigger.
		$tall  = $h > $w;
		$pad   = $tall ? 84 : 72;
		$big   = $tall ? 1.3 : 1.0;

		// The pulse line along the bottom.
		self::pulse( $img, $col( $c['line'] ), $h - ( $tall ? 190 : 110 ), $w );

		// Logo, top left.
		$top = $pad;
		if ( '' !== $logo && is_readable( $logo ) ) {
			$data = (string) file_get_contents( $logo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
			$src  = '' !== $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad logo file just means no logo.
			if ( false !== $src ) {
				$lw = imagesx( $src );
				$lh = imagesy( $src );
				$lh2 = (int) round( 96 * $big );
				$lw2 = (int) round( $lw * $lh2 / max( 1, $lh ) );
				imagecopyresampled( $img, $src, $pad - 6, $top - 18, 0, 0, $lw2, $lh2, $lw, $lh );
				imagedestroy( $src );
				if ( '' !== $label ) {
					self::text( $img, (int) round( 26 * $big ), $pad + $lw2 + 16, $top + (int) round( 40 * $big ), $col( $c['label'] ), $font, mb_strtoupper( $label ), 2 );
				}
				$top += $lh2 + 10;
			}
		} elseif ( '' !== $label ) {
			self::text( $img, 26, $pad, $top + 26, $col( $c['label'] ), $font, mb_strtoupper( $label ), 2 );
			$top += 60;
		}

		// The question: the largest size that fits.
		$width  = $w - 2 * $pad;
		$bottom = $h - ( $tall ? 250 : 150 );
		$lines  = array();
		$size   = $tall ? 92 : 68;
		for ( ; $size >= 30; $size -= 2 ) {
			$lines  = self::wrap( $title, $font, $size, $width );
			$height = count( $lines ) * (int) round( $size * 1.25 );
			if ( $height <= $bottom - $top - 20 ) {
				break;
			}
		}
		$line_h = (int) round( $size * 1.25 );
		$block  = count( $lines ) * $line_h;
		$y      = $top + (int) max( 20, ( $bottom - $top - $block ) / 2 ) + $size;
		foreach ( $lines as $line ) {
			self::text( $img, $size, $pad, $y, $col( $c['text'] ), $font, $line );
			$y += $line_h;
		}

		// The site's address, bottom right.
		if ( '' !== $host ) {
			$hs  = (int) round( 28 * $big );
			$box = imagettfbbox( $hs, 0, $font, $host );
			$tw  = $box ? abs( $box[2] - $box[0] ) : 0;
			self::text( $img, $hs, $w - $pad - $tw, $h - ( $tall ? 70 : 40 ), $col( $c['accent'] ), $font, $host );
		}

		$ok = $jpeg ? imagejpeg( $img, $path, 90 ) : imagepng( $img, $path, 6 );
		imagedestroy( $img );
		return (bool) $ok;
	}

	/**
	 * Break text into lines no wider than $width.
	 *
	 * @return list<string>
	 */
	private static function wrap( string $text, string $font, int $size, int $width ): array {
		$lines = array();
		$line  = '';
		foreach ( explode( ' ', $text ) as $word ) {
			// A single word too long for a line is broken up.
			while ( self::width( $word, $font, $size ) > $width && mb_strlen( $word ) > 1 ) {
				$cut = mb_strlen( $word ) - 1;
				while ( $cut > 1 && self::width( mb_substr( $word, 0, $cut ), $font, $size ) > $width ) {
					--$cut;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
				}
				$lines[] = mb_substr( $word, 0, $cut );
				$word    = mb_substr( $word, $cut );
			}
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && self::width( $try, $font, $size ) > $width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	private static function width( string $text, string $font, int $size ): int {
		$box = imagettfbbox( $size, 0, $font, $text );
		return $box ? abs( $box[2] - $box[0] ) : 0;
	}

	/** @param \GdImage $img Image. */
	private static function text( $img, int $size, int $x, int $y, int $color, string $font, string $text, int $spacing = 0 ): void {
		if ( 0 === $spacing ) {
			imagettftext( $img, $size, 0, $x, $y, $color, $font, $text );
			return;
		}
		foreach ( mb_str_split( $text ) as $char ) {
			imagettftext( $img, $size, 0, $x, $y, $color, $font, $char );
			$x += self::width( $char, $font, $size ) + $spacing + ( ' ' === $char ? (int) ( $size * 0.3 ) : 0 );
		}
	}

	/**
	 * A heartbeat line across the image, drawn thick and smooth enough at this size.
	 *
	 * @param \GdImage $img Image.
	 */
	private static function pulse( $img, int $color, int $base, int $w = self::WIDTH ): void {
		$points = array( array( 0, 0 ), array( 0.52, 0 ), array( 0.55, -22 ), array( 0.585, 34 ), array( 0.62, -60 ), array( 0.655, 30 ), array( 0.68, 0 ), array( 1, 0 ) );
		imagesetthickness( $img, 6 );
		for ( $i = 1, $n = count( $points ); $i < $n; $i++ ) {
			imageline( $img, (int) ( $points[ $i - 1 ][0] * $w ), $base + (int) $points[ $i - 1 ][1], (int) ( $points[ $i ][0] * $w ), $base + (int) $points[ $i ][1], $color );
		}
		imagesetthickness( $img, 1 );
	}

	/** @return array{0: int, 1: int, 2: int} */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}
}
