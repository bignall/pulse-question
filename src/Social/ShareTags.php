<?php
/**
 * Share tags (Open Graph and X/Twitter cards) on each question's page, so a shared link shows
 * the question, its background and the share image.
 *
 * With Yoast SEO or Rank Math active, they write the tags; the plugin only gives them the share
 * image. Other SEO plugins that write their own tags are left alone.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Social;

use PulseQuestion\Questions;
use PulseQuestion\Settings;

final class ShareTags {

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'tags' ), 5 );
		// Yoast SEO.
		add_action( 'wpseo_add_opengraph_images', array( self::class, 'yoastImage' ) );
		add_filter( 'wpseo_twitter_image', array( self::class, 'imageUrl' ) );
		// Rank Math.
		add_filter( 'rank_math/opengraph/facebook/image', array( self::class, 'imageUrl' ) );
		add_filter( 'rank_math/opengraph/twitter/image', array( self::class, 'imageUrl' ) );
		add_action( 'before_delete_post', array( self::class, 'forget' ) );
	}

	/** Whether an SEO plugin that writes share tags is active. */
	public static function seoPlugin(): string {
		$plugins = array(
			'WPSEO_VERSION'         => 'Yoast SEO',
			'RANK_MATH_VERSION'     => 'Rank Math',
			'AIOSEO_VERSION'        => 'All in One SEO',
			'SEOPRESS_VERSION'      => 'SEOPress',
			'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
			'SLIM_SEO_VER'          => 'Slim SEO',
		);
		foreach ( $plugins as $constant => $name ) {
			if ( defined( $constant ) ) {
				return $name;
			}
		}
		/** @param string $name Name of an SEO plugin writing share tags, or '' for none. */
		return (string) apply_filters( 'pulse_question_seo_plugin', '' );
	}

	private static function questionId(): int {
		return is_singular( Questions::POST_TYPE ) ? (int) get_queried_object_id() : 0;
	}

	public static function tags(): void {
		$id = self::questionId();
		if ( ! $id || ! Settings::get( 'share_tags' ) || '' !== self::seoPlugin() ) {
			return;
		}
		$post  = get_post( $id );
		$title = \PulseQuestion\Text::plain( get_the_title( $post ) );
		$desc  = trim( wp_strip_all_tags( (string) $post->post_content ) );
		if ( '' === $desc ) {
			$desc = sprintf(
				/* translators: 1: label such as "Question of the day", 2: site name. */
				__( '%1$s on %2$s. Share your answer.', 'pulse-question' ),
				(string) Settings::get( 'label' ),
				\PulseQuestion\Text::plain( (string) get_bloginfo( 'name' ) )
			);
		}
		$desc  = wp_html_excerpt( $desc, 200, '…' );
		$image = ShareImage::forQuestion( $id );
		$meta  = array(
			array( 'property', 'og:type', 'article' ),
			array( 'property', 'og:site_name', \PulseQuestion\Text::plain( (string) get_bloginfo( 'name' ) ) ),
			array( 'property', 'og:title', $title ),
			array( 'property', 'og:description', $desc ),
			array( 'property', 'og:url', (string) get_permalink( $post ) ),
			array( 'name', 'twitter:card', $image ? 'summary_large_image' : 'summary' ),
			array( 'name', 'twitter:title', $title ),
			array( 'name', 'twitter:description', $desc ),
		);
		if ( $image ) {
			$meta[] = array( 'property', 'og:image', $image['url'] );
			$meta[] = array( 'property', 'og:image:width', (string) $image['width'] );
			$meta[] = array( 'property', 'og:image:height', (string) $image['height'] );
			$meta[] = array( 'property', 'og:image:alt', $title );
			$meta[] = array( 'name', 'twitter:image', $image['url'] );
		}
		echo "\n<!-- Pulse Question share tags -->\n";
		foreach ( $meta as [ $attr, $key, $value ] ) {
			printf( '<meta %s="%s" content="%s" />' . "\n", esc_attr( $attr ), esc_attr( $key ), esc_attr( $value ) );
		}
	}

	/** Yoast: add the share image when the question has no image of its own. */
	public static function yoastImage( $container ): void {
		$id = self::questionId();
		if ( $id && is_object( $container ) && method_exists( $container, 'has_images' ) && ! $container->has_images() && method_exists( $container, 'add_image_by_url' ) ) {
			$image = ShareImage::forQuestion( $id );
			if ( $image ) {
				$container->add_image_by_url( $image['url'] );
			}
		}
	}

	/** Rank Math and Yoast's X image: the share image on question pages. */
	public static function imageUrl( $url ) {
		$id = self::questionId();
		if ( $id && ( empty( $url ) || ! get_post_thumbnail_id( $id ) ) ) {
			$image = ShareImage::forQuestion( $id );
			if ( $image ) {
				return $image['url'];
			}
		}
		return $url;
	}

	public static function forget( int $post_id ): void {
		if ( get_post_type( $post_id ) === Questions::POST_TYPE ) {
			ShareImage::forget( $post_id );
		}
	}
}
