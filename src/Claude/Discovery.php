<?php
/**
 * Tells the Anthropic SDK's HTTP discovery to use WordPress for requests and nyholm/psr7 for
 * messages. The SDK looks these up when a client is made, before it reads its options.
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Claude;

use PulseQuestion\Vendor\Http\Discovery\ClassDiscovery;
use PulseQuestion\Vendor\Http\Discovery\Strategy\DiscoveryStrategy;
use PulseQuestion\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use PulseQuestion\Vendor\Psr\Http\Client\ClientInterface;
use PulseQuestion\Vendor\Psr\Http\Message\RequestFactoryInterface;
use PulseQuestion\Vendor\Psr\Http\Message\ResponseFactoryInterface;
use PulseQuestion\Vendor\Psr\Http\Message\ServerRequestFactoryInterface;
use PulseQuestion\Vendor\Psr\Http\Message\StreamFactoryInterface;
use PulseQuestion\Vendor\Psr\Http\Message\UploadedFileFactoryInterface;
use PulseQuestion\Vendor\Psr\Http\Message\UriFactoryInterface;

final class Discovery implements DiscoveryStrategy {

	private static bool $registered = false;

	public static function register(): void {
		if ( ! self::$registered ) {
			ClassDiscovery::prependStrategy( self::class );
			self::$registered = true;
		}
	}

	/**
	 * @param string $type Interface being looked for.
	 * @return list<array{class: class-string, condition: class-string}>
	 */
	public static function getCandidates( $type ) {
		if ( ClientInterface::class === $type ) {
			return array(
				array(
					'class'     => WpHttpClient::class,
					'condition' => WpHttpClient::class,
				),
			);
		}
		$factories = array( RequestFactoryInterface::class, ResponseFactoryInterface::class, StreamFactoryInterface::class, UriFactoryInterface::class, ServerRequestFactoryInterface::class, UploadedFileFactoryInterface::class );
		if ( in_array( $type, $factories, true ) ) {
			return array(
				array(
					'class'     => Psr17Factory::class,
					'condition' => Psr17Factory::class,
				),
			);
		}
		return array();
	}
}
