<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Models\Customer;

/**
 * Resolves where a cash payment goes (§7.2).
 *
 * RULE
 * ====
 *   If the customer's zone has a Distributor → cash goes to the Distributor.
 *   If the customer's zone has no Distributor (zona directa) → cash goes to Dermacells.
 *
 * This resolution is automatic: the Seller never decides, the system decides and
 * informs the Seller at payment-registration time.
 *
 * The decision is captured in a value object so callers can read both the
 * destination label and the optional distributor ID without parsing strings.
 */
final class CashDestinationResolver
{
    /**
     * Resolve the cash destination for a payment on the given customer's zone.
     *
     * The customer's zone relationship must be loaded (or will be lazy-loaded).
     */
    public function resolveFor(Customer $customer): CashDestinationDecision
    {
        $zone = $customer->zone;

        // Zona directa: no distributor assigned
        if ($zone === null || $zone->distributor_id === null) {
            return CashDestinationDecision::dermacells();
        }

        return CashDestinationDecision::distributor($zone->distributor_id);
    }
}
