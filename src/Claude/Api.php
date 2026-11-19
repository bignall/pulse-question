<?php
/**
 * Makes Anthropic API clients for the plugin (question writing and answer moderation).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Claude;

use PulseQuestion\Vendor\Anthropic\Client;

final class Api {

	/**
	 * A client using the plugin's key, WordPress for HTTP, and the given limits.
	 *
	 * @param float $timeout     Seconds per try.
	 * @param int   $max_retries Retries after the first try (rate limits, overload, network).
	 */
	public static function client( float $timeout = 180.0, int $max_retries = 2 ): Client {
		Discovery::register();
		return new Client(
			apiKey: Generator::apiKey(),
			/**
			 * The Anthropic API address (for a proxy or gateway). Null for the default.
			 *
			 * @param string|null $url Default null.
			 */
			baseUrl: apply_filters( 'pulse_question_claude_base_url', null ),
			requestOptions: array(
				'timeout'    => $timeout,
				'maxRetries' => $max_retries,
			)
		);
	}
}
