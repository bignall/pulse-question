<?php
/**
 * A request through WordPress didn't get an answer (timeout, DNS, SSL...).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Claude;

use PulseQuestion\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use PulseQuestion\Vendor\Psr\Http\Message\RequestInterface;

final class WpHttpException extends \RuntimeException implements NetworkExceptionInterface {

	public function __construct( string $message, private RequestInterface $request ) {
		parent::__construct( $message );
	}

	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
