<?php
/**
 * One row per vote: a member's vote for a question or an answer.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Answers;

use PulseQuestion\Vendor\Bignall\WPFoundation\Database\Table;

final class VotesTable extends Table {

	public function name(): string {
		return 'pq_votes';
	}

	public function columns(): string {
		return 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(10) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY one_vote (object_type,object_id,user_id),
KEY object (object_type,object_id)';
	}
}
