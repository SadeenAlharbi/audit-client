<?php

declare(strict_types=1);

namespace Company\AuditClient\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The platform could not be reached or asked us to come back later
 * (network error, 408, 429, 5xx). Sending again later may succeed.
 */
final class TransientDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
