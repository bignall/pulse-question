<?php
/**
 * Votes on questions and answers: one per member, press again to take it back.
 *
 * Each vote is a row in the votes table; the total is also kept in meta (_pq_votes on the
 * question or the answer) so lists can sort by it.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Answers;

use PulseQuestion\Questions;

final class Votes {

	public const META = '_pq_votes';

	private static function table(): string {
		return ( new VotesTable() )->tableName();
	}

	/** Whether this is something that can be voted on: an asked question, or an approved answer on one. */
	public static function votable( string $type, int $id ): bool {
		if ( 'question' === $type ) {
			return Questions::POST_TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id );
		}
		if ( 'answer' === $type ) {
			$comment = get_comment( $id );
			return $comment instanceof \WP_Comment && '1' === $comment->comment_approved && Answers::isAnswer( $comment );
		}
		return false;
	}

	/**
	 * Vote, or take the vote back.
	 *
	 * @return array{voted: bool, count: int}|\WP_Error
	 */
	public static function toggle( string $type, int $id, int $user_id ): array|\WP_Error {
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'pq_vote_login', __( 'Log in to vote.', 'pulse-question' ) );
		}
		if ( ! self::votable( $type, $id ) ) {
			return new \WP_Error( 'pq_vote_target', __( 'That can\'t be voted on.', 'pulse-question' ) );
		}
		if ( 'answer' === $type && (int) get_comment( $id )->user_id === $user_id ) {
			return new \WP_Error( 'pq_vote_own', __( 'You can\'t vote for your own answer.', 'pulse-question' ) );
		}
		global $wpdb;
		$table = self::table();
		$where = array(
			'object_type' => $type,
			'object_id'   => $id,
			'user_id'     => $user_id,
		);
		if ( self::hasVoted( $type, $id, $user_id ) ) {
			$wpdb->delete( $table, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$voted = false;
		} else {
			// INSERT IGNORE: a double click can't count twice (the table has a unique key).
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "INSERT IGNORE INTO {$table} (object_type, object_id, user_id, created_at) VALUES (%s, %d, %d, %s)", $type, $id, $user_id, current_time( 'mysql', true ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$voted = true;
		}
		return array(
			'voted' => $voted,
			'count' => self::recount( $type, $id ),
		);
	}

	public static function hasVoted( string $type, int $id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE object_type = %s AND object_id = %d AND user_id = %d", $type, $id, $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Which of these the member voted for.
	 *
	 * @param list<int> $ids Ids.
	 * @return array<int, true>
	 */
	public static function votedAmong( string $type, array $ids, int $user_id ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( $user_id <= 0 || ! $ids ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$in    = implode( ',', $ids );
		$rows  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT object_id FROM {$table} WHERE object_type = %s AND user_id = %d AND object_id IN ({$in})", $type, $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return array_fill_keys( array_map( 'intval', $rows ), true );
	}

	public static function count( string $type, int $id ): int {
		return (int) ( 'question' === $type ? get_post_meta( $id, self::META, true ) : get_comment_meta( $id, self::META, true ) );
	}

	private static function recount( string $type, int $id ): int {
		global $wpdb;
		$table = self::table();
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = %s AND object_id = %d", $type, $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( 'question' === $type ) {
			update_post_meta( $id, self::META, $count );
		} else {
			update_comment_meta( $id, self::META, $count );
		}
		return $count;
	}

	/** A member was deleted: drop their votes and correct the totals they were part of. */
	public static function forgetUser( int $user_id ): void {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT object_type, object_id FROM {$table} WHERE user_id = %d", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$wpdb->delete( $table, array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			self::recount( (string) $row->object_type, (int) $row->object_id );
		}
	}

	/** Drop the votes of a deleted answer or question. */
	public static function forget( string $type, int $id ): void {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; counts are cached in meta.
			self::table(),
			array(
				'object_type' => $type,
				'object_id'   => $id,
			)
		);
	}
}
