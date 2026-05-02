<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Sale state transition (typically Confirm) is attempted while
 * the sale has one or more pending authorization requests (§13.2).
 *
 * The SaleController maps this to an RFC 7807 409 Conflict response with
 * business code PENDING_AUTHORIZATION_EXISTS so the client can surface an
 * actionable message directing the user to wait for Director resolution.
 */
final class OperationBlockedByPendingAuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $saleId,
    ) {
        parent::__construct(
            "Sale {$saleId} cannot be confirmed: one or more authorization requests are pending resolution."
        );
    }
}
