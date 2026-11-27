<?php
/**
 * Plugin definition: lifecycle handled by WP Foundation.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion;

use PulseQuestion\Vendor\Bignall\WPFoundation\Plugin;

final class Bootstrap {

	public const PREFIX = 'pulse_question';

	public static function plugin( string $file ): Plugin {
		// Data saved before 0.6.0 used the prefix pq_; move it before WP Foundation reads its version.
		PrefixMigration::maybeRun();
		add_action( 'init', array( PrefixMigration::class, 'maybeRecount' ), 99 );
		return Plugin::create( $file, self::PREFIX )
			->requires( php: '8.1', wp: '6.4' )
			->settings( Settings::store() )
			->options(
				array(
					Daily::OPTION => array(),
					Claude\Generator::LOG => array(),
					Members\MailingList::LOG => array(),
					Email\DailyEmail::STATE => array(),
					Email\DailyEmail::LOG => array(),
					Social\Webhook::STATE => array(),
					Social\Webhook::LOG => array(),
					'pulse_question_social_secret'       => '',
					Admin\Setup::OPTION     => array(),
				)
			)
			->tables( new Answers\VotesTable() )
			->task( new LifecycleTask() )
			->capabilities(
				array(
					'administrator' => array( Questions::CAPABILITY ),
					'editor' => array( Questions::CAPABILITY ),
				)
			)
			->cron( Daily::CRON_HOOK, 'hourly' )
			->postTypes( Questions::POST_TYPE )
			->taxonomies( Questions::TAXONOMY )
			->flushRewriteRules()
			->deactivationSurvey()
			->deleteDataOnUninstall( static fn (): bool => (bool) Settings::get( 'remove_data' ) )
			->onReady( static fn ( Plugin $plugin ) => App::boot( $plugin ) );
	}
}
