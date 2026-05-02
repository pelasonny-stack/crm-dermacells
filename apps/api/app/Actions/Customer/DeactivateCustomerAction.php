<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Exceptions\CustomerHasPendingObligationsException;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a customer, with blocking checks for pending obligations (§3.10).
 *
 * BLOCKING CHECKS
 * ===============
 * When `$force = false` (default), the action checks for:
 *   1. Pending account balance    — Phase 7 (TODO: implement when Phase 7 ships)
 *   2. Open sales (Borrador/Confirmada) — Phase 5 (TODO: implement when Phase 5 ships)
 *   3. Overdue unpaid collections — Phase 7 (TODO: implement when Phase 7 ships)
 *
 * If any check fails, CustomerHasPendingObligationsException is thrown (HTTP 409).
 *
 * When `$force = true` (Director only), all checks are skipped and the
 * customer is deactivated regardless. The forced deactivation is logged in the
 * audit trail via the AuditObserver (applied to Customer via the Auditable trait).
 *
 * IMPORTANT: The `$force` flag is only honoured by DirectorController logic.
 * Do not pass `$force = true` unless the caller has already verified the user
 * is a Director.
 */
class DeactivateCustomerAction
{
    public function execute(Customer $customer, User $deactivatedBy, string $reason, bool $force = false): Customer
    {
        if (! $force) {
            $this->assertNoBlockingObligations($customer);
        }

        return DB::transaction(function () use ($customer, $deactivatedBy, $reason): Customer {
            $customer->update([
                'is_active'           => false,
                'deactivated_at'      => now(),
                'deactivated_by'      => $deactivatedBy->id,
                'deactivation_reason' => $reason,
            ]);

            return $customer->fresh();
        });
    }

    /**
     * Collects all active blocking conditions and throws if any exist.
     *
     * Each check is a separate method so Phase 5 and Phase 7 can fill in the
     * TODO stubs without touching the orchestration logic here.
     *
     * @throws CustomerHasPendingObligationsException
     */
    private function assertNoBlockingObligations(Customer $customer): void
    {
        $reasons = [];

        // TODO Phase 7: Uncomment and implement pending account balance check
        // if ($this->hasPendingAccountBalance($customer)) {
        //     $reasons[] = 'El cliente tiene saldo pendiente en cuenta corriente.';
        // }

        // TODO Phase 5: Uncomment and implement open sales check
        // if ($this->hasOpenSales($customer)) {
        //     $reasons[] = 'El cliente tiene ventas en estado Borrador o Confirmada.';
        // }

        // TODO Phase 7: Uncomment and implement overdue collections check
        // if ($this->hasOverdueCollections($customer)) {
        //     $reasons[] = 'El cliente tiene cobros vencidos sin saldar.';
        // }

        if (! empty($reasons)) {
            throw new CustomerHasPendingObligationsException($reasons);
        }
    }

    // TODO Phase 7: pending balance check
    // private function hasPendingAccountBalance(Customer $customer): bool
    // {
    //     return CustomerAccountBalance::where('customer_id', $customer->id)
    //         ->where(fn ($q) => $q->where('ars_balance', '>', 0)->orWhere('usd_balance', '>', 0))
    //         ->exists();
    // }

    // TODO Phase 5: open sales check
    // private function hasOpenSales(Customer $customer): bool
    // {
    //     return Sale::where('customer_id', $customer->id)
    //         ->whereIn('status', ['draft', 'confirmed'])
    //         ->exists();
    // }

    // TODO Phase 7: overdue collections check
    // private function hasOverdueCollections(Customer $customer): bool
    // {
    //     return Payment::where('customer_id', $customer->id)
    //         ->where('due_date', '<', now()->toDateString())
    //         ->where('is_settled', false)
    //         ->exists();
    // }
}
