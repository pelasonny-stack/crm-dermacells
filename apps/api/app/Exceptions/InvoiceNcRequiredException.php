<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a cancellation or partial return is attempted on a sale that
 * has an existing invoice but no corresponding credit note has been issued
 * yet in Xubio (§5.6, §5.8).
 *
 * HTTP status: 409 Conflict
 * Response code: INVOICE_NC_REQUIRED
 *
 * Phase 6 will catch this in the billing flow and route to the NC creation
 * workflow. Phase 5 simply surfaces it to the caller.
 */
final class InvoiceNcRequiredException extends RuntimeException
{
    public function getStatusCode(): int
    {
        return 409;
    }

    /**
     * Machine-readable code for the API error response body.
     */
    public function getApiCode(): string
    {
        return 'INVOICE_NC_REQUIRED';
    }
}
