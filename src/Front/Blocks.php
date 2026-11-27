<?php
/**
 * Two blocks for the block editor and Site Editor, drawn on the server:
 * "Question of the day" and "Past questions". No build step: assets/blocks.js uses the
 * editor's own React and ServerSideRender.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Front;

use PulseQuestion\App;

final class Blocks {

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		wp_register_script(
			'pulse-question-blocks',
			App::url( 'assets/blocks.js' ),
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			App::version(),
			true
		);
		register_block_type(
			'pulse-question/today',
			array(
				'api_version'     => 3,
				'editor_script'   => 'pulse-question-blocks',
				'attributes'      => array(
					'background' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					// -1: the number in Settings.
					'answers'    => array(
						'type'    => 'number',
						'default' => -1,
					),
				),
				'render_callback' => static fn ( array $a ): string => Render::today( (bool) ( $a['background'] ?? true ), (int) ( $a['answers'] ?? -1 ) ),
			)
		);
		register_block_type(
			'pulse-question/past',
			array(
				'api_version'     => 3,
				'editor_script'   => 'pulse-question-blocks',
				'attributes'      => array(
					'count' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'paged' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => static fn ( array $a ): string => Render::past( (int) ( $a['count'] ?? 0 ), 0, (bool) ( $a['paged'] ?? true ) ),
			)
		);
	}
}
