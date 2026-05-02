<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a sale state transition violates the state machine rules
 * or the caller's authorization to perform the transition.
 *
 * The controller catches this and returns a 422 or 403 response with
 * RFC 7807 problem+json body.
 */
final class InvalidSaleTransitionException extends RuntimeException
{
    public function __construct(string $message = '', private readonly int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
