<?php

declare(strict_types=1);

namespace App\Services\Xubio\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when Xubio returns a 4xx business error (validation rejection,
 * AFIP refusal, missing client, etc.). The response payload is preserved
 * for Director review.
 */
class XubioRejectedException extends RuntimeException
{
    /**
     * @param array<string,mixed> $responsePayload
     */
    public function __construct(
        string $message,
        public readonly array $responsePayload = [],
        public readonly int $statusCode = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
