<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by DeactivateCustomerAction when a customer cannot be deactivated
 * because they have pending obligations (§3.10):
 *
 *   - Pending balance in account (customer_account_balances — Phase 7)
 *   - Open sales in state Borrador or Confirmada (sales — Phase 5)
 *   - Overdue collections (payments past due date — Phase 7)
 *
 * The controller catches this exception and returns HTTP 409 with the RFC 7807
 * problem+json body:
 *
 *   {
 *     "type":   "https://dermacells.com/errors/customer-has-pending-obligations",
 *     "title":  "Customer Has Pending Obligations",
 *     "status": 409,
 *     "detail": "<human readable detail>",
 *     "code":   "CUSTOMER_HAS_PENDING_OBLIGATIONS"
 *   }
 *
 * Directors can bypass this check by passing `force=true` in the request.
 * When forced, the action skips the blocking checks and deactivates with an
 * explicit audit_log entry.
 */
class CustomerHasPendingObligationsException extends RuntimeException
{
    /**
     * @param list<string> $reasons Human-readable reasons why deactivation is blocked.
     */
    public function __construct(
        private readonly array $reasons = [],
        string $message = 'El cliente tiene obligaciones pendientes y no puede ser dado de baja.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
