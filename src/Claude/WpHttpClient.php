<?php
/**
 * A PSR-18 HTTP client that sends requests through WordPress (wp_remote_request), so the Anthropic
 * SDK uses the site's own HTTP settings (proxies, SSL certificates, timeouts filters) and no extra
 * HTTP library has to be bundled.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Claude;

use PulseQuestion\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use PulseQuestion\Vendor\Psr\Http\Client\ClientInterface;
use PulseQuestion\Vendor\Psr\Http\Message\RequestInterface;
use PulseQuestion\Vendor\Psr\Http\Message\ResponseInterface;

final class WpHttpClient implements ClientInterface {

	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[ $name ] = implode( ', ', $values );
		}
		/**
		 * Seconds to wait for the Anthropic API. Writing a batch of questions can take a minute or two.
		 *
		 * @param int $timeout Default 180.
		 */
		$timeout  = (int) apply_filters( 'pulse_question_claude_timeout', 180 );
		$response = wp_remote_request(
			(string) $request->getUri(),
			array(
				'method'      => $request->getMethod(),
				'headers'     => $headers,
				'body'        => (string) $request->getBody(),
				'timeout'     => $timeout,
				'redirection' => 0,
				'httpversion' => '1.1',
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new WpHttpException( $response->get_error_message(), $request );
		}

		$factory = new Psr17Factory();
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$psr     = $factory->createResponse( $code > 0 ? $code : 500, (string) wp_remote_retrieve_response_message( $response ) );
		$raw     = wp_remote_retrieve_headers( $response );
		$all     = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		foreach ( $all as $name => $value ) {
			$psr = $psr->withHeader( (string) $name, is_array( $value ) ? array_map( 'strval', $value ) : (string) $value );
		}
		return $psr->withBody( $factory->createStream( (string) wp_remote_retrieve_body( $response ) ) );
	}
}
