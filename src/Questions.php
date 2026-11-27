<?php
/**
 * Questions: a post type with categories, moving through
 *
 *     draft → waiting for approval (pending) → approved (in the queue) → asked (published)
 *
 * The title is the question; the content is optional background shown under it. A question only
 * goes public when it's picked as the question of the day (or asked now by hand), never straight
 * from the editor, so the queue decides what's next. The days a question was asked are kept in
 * meta (it can be asked again when the queue runs out).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

final class Questions {

	public const POST_TYPE       = 'pulse_question';
	public const TAXONOMY        = 'pulse_question_category';
	public const STATUS_APPROVED = 'pulseq_approved'; // Post statuses can be at most 20 characters.
	public const CAPABILITY      = 'manage_pulse_questions';

	/** One row per day asked (Y-m-d). */
	public const META_ASKED = '_pulse_question_asked';
	/** The last day asked (Y-m-d), for sorting. */
	public const META_LAST = '_pulse_question_last_asked';
	/** Where it came from: mine, or later ai. */
	public const META_SOURCE = '_pulse_question_source';

	/** Set while the daily pick publishes a question. */
	private static bool $asking = false;

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 5 );
		add_filter( 'wp_insert_post_data', array( self::class, 'guardStatus' ), 10, 2 );
	}

	public static function register(): void {
		$cap = self::CAPABILITY;

		// Taxonomy first, so /questions/category/x/ isn't taken for a question's attachment page.
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Question Categories', 'pulse-question' ),
					'singular_name' => __( 'Question Category', 'pulse-question' ),
					'menu_name'     => __( 'Categories', 'pulse-question' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'       => 'questions/category',
					'with_front' => false,
				),
				'capabilities'      => array(
					'manage_terms' => $cap,
					'edit_terms'   => $cap,
					'delete_terms' => $cap,
					'assign_terms' => $cap,
				),
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Questions', 'pulse-question' ),
					'singular_name'      => __( 'Question', 'pulse-question' ),
					'add_new'            => __( 'Add Question', 'pulse-question' ),
					'add_new_item'       => __( 'Add Question', 'pulse-question' ),
					'edit_item'          => __( 'Edit Question', 'pulse-question' ),
					'view_item'          => __( 'View Question', 'pulse-question' ),
					'search_items'       => __( 'Search Questions', 'pulse-question' ),
					'not_found'          => __( 'No questions found.', 'pulse-question' ),
					'all_items'          => __( 'All Questions', 'pulse-question' ),
					'archives'           => __( 'Questions', 'pulse-question' ),
					'menu_name'          => __( 'Questions', 'pulse-question' ),
				),
				'public'          => true,
				'has_archive'     => 'questions',
				'rewrite'         => array(
					'slug'       => 'question',
					'with_front' => false,
				),
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-format-chat',
				'menu_position'   => 22,
				'supports'        => array( 'title', 'editor', 'excerpt', 'revisions' ),
				'taxonomies'      => array( self::TAXONOMY ),
				'map_meta_cap'    => true,
				'capability_type' => 'pulse_question',
				'capabilities'    => array(
					'create_posts'           => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_published_posts'   => $cap,
					'edit_private_posts'     => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'delete_private_posts'   => $cap,
					'read'                   => 'read',
				),
			)
		);

		register_post_status(
			self::STATUS_APPROVED,
			array(
				'label'                     => _x( 'Approved', 'question status', 'pulse-question' ),
				'public'                    => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of questions. */
				'label_count'               => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved <span class="count">(%s)</span>', 'pulse-question' ),
			)
		);
	}

	/**
	 * Only the daily pick publishes. From the editor, a question is a draft, waiting for approval
	 * or approved; one that's been asked stays asked.
	 *
	 * @param array<string, mixed> $data    Post data about to be saved.
	 * @param array<string, mixed> $postarr Raw submitted data.
	 * @return array<string, mixed>
	 */
	public static function guardStatus( array $data, array $postarr ): array {
		if ( self::POST_TYPE !== ( $data['post_type'] ?? '' ) || self::$asking ) {
			return $data;
		}
		$status = (string) ( $data['post_status'] ?? '' );
		if ( in_array( $status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return $data;
		}
		$was = ! empty( $postarr['ID'] ) ? (string) get_post_status( (int) $postarr['ID'] ) : '';
		if ( 'publish' === $was && 'trash' !== $status ) {
			$data['post_status'] = 'publish';
			return $data;
		}
		if ( isset( $postarr['pulse_question_status'] ) ) {
			$data['post_status'] = match ( (string) $postarr['pulse_question_status'] ) {
				'approved' => self::STATUS_APPROVED,
				'pending'  => 'pending',
				default    => 'draft',
			};
		} elseif ( in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			$data['post_status'] = self::STATUS_APPROVED;
		}
		// Newly approved questions join the end of the queue.
		if ( self::STATUS_APPROVED === $data['post_status'] && self::STATUS_APPROVED !== $was ) {
			$data['menu_order'] = self::nextOrder();
		}
		return $data;
	}

	/**
	 * Add a question.
	 *
	 * @param string $status draft, pending or approved.
	 * @param string $source Where it came from, e.g. mine.
	 */
	public static function add( string $title, string $status = 'approved', string $source = 'mine', string $background = '' ): int|\WP_Error {
		$title = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) {
			return new \WP_Error( 'pulse_question_empty', __( 'A question needs some words.', 'pulse-question' ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_title'   => $title,
				'post_content' => wp_kses_post( $background ),
				'post_status'  => 'draft',
				'pulse_question_status'    => $status,
				'menu_order'   => self::nextOrder(),
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, self::META_SOURCE, sanitize_key( $source ) );
		}
		return $id;
	}

	/** Move a question waiting for approval (or a draft) into the queue, at the end. */
	public static function approve( int $id ): bool {
		if ( ! in_array( get_post_status( $id ), array( 'pending', 'draft' ), true ) || get_post_type( $id ) !== self::POST_TYPE ) {
			return false;
		}
		return ! is_wp_error(
			wp_update_post(
				array(
					'ID'         => $id,
					'pulse_question_status'  => 'approved',
					'menu_order' => self::nextOrder(),
				),
				true
			)
		);
	}

	/**
	 * Make a question today's: publish it, record the day, and set its date to now, so a post's
	 * date is always when it was last asked (builders and themes can sort and show it as usual).
	 */
	public static function markAsked( int $id, string $day ): void {
		self::$asking = true;
		$now          = current_time( 'mysql' );
		wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_date'     => $now,
				'post_date_gmt' => get_gmt_from_date( $now ),
				'edit_date'     => true,
			)
		);
		self::$asking = false;
		add_post_meta( $id, self::META_ASKED, $day );
		update_post_meta( $id, self::META_LAST, $day );
	}

	/** Save a new queue order: the ids first to last. */
	public static function reorder( array $ids ): void {
		$n = 1;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( get_post_status( $id ) === self::STATUS_APPROVED ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'menu_order' => $n++,
					)
				);
			}
		}
	}

	/**
	 * Approved questions in queue order.
	 *
	 * @return list<int>
	 */
	public static function queue(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => self::STATUS_APPROVED,
					'numberposts'      => -1,
					'fields'           => 'ids',
					'orderby'          => array(
						'menu_order' => 'ASC',
						'date'       => 'ASC',
						'ID'         => 'ASC',
					),
					'suppress_filters' => true,
				)
			)
		);
	}

	/**
	 * Questions waiting for approval, oldest first.
	 *
	 * @return list<int>
	 */
	public static function waiting(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'pending',
					'numberposts'      => -1,
					'fields'           => 'ids',
					'orderby'          => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
					'suppress_filters' => true,
				)
			)
		);
	}

	/**
	 * Asked questions => the day each was last asked.
	 *
	 * @return array<int, string>
	 */
	public static function asked(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = 'publish'",
				self::META_LAST,
				self::POST_TYPE
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->ID ] = (string) ( $row->meta_value ?? '' );
		}
		return $out;
	}

	/**
	 * The question asked most recently before $day (or before today's), other than $today_id; 0 if none.
	 * On a tie (Ask now used that day), the newer question.
	 */
	public static function previous( int $today_id, string $day ): int {
		$best = 0;
		$when = '';
		foreach ( array_keys( self::asked() ) as $id ) {
			if ( $id === $today_id ) {
				continue;
			}
			foreach ( self::askedDays( $id ) as $asked ) {
				if ( ( '' === $day || $asked < $day ) && ( $asked > $when || ( $asked === $when && $id > $best ) ) ) {
					$best = $id;
					$when = $asked;
				}
			}
		}
		return $best;
	}

	/**
	 * Days a question was asked, newest first.
	 *
	 * @return list<string>
	 */
	public static function askedDays( int $id ): array {
		$days = array_map( 'strval', (array) get_post_meta( $id, self::META_ASKED ) );
		rsort( $days );
		return array_values( array_unique( $days ) );
	}

	private static function nextOrder(): int {
		global $wpdb;
		$max = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE )
		);
		return (int) $max + 1;
	}
}
