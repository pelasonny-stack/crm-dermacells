<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CustomerReassignmentService — §3.11 bulk/individual client reassignment.
 *
 * Only Directors may perform mass reassignment. The service:
 *   1. Validates that the initiating user is a Director.
 *   2. Updates customers.assigned_seller_id for each customer in $customerIds.
 *   3. Does NOT touch open sales or pending payments — they remain under the
 *      original seller's name (§3.11 spec: "ventas abiertas y cobros pendientes
 *      del Vendedor desactivado quedan bajo su nombre").
 *   4. Writes one audit_log row per customer via AuditObserver (triggered by
 *      the Eloquent save() call on each Customer).
 *
 * All updates are wrapped in a single DB transaction; if any row fails, the
 * entire batch rolls back to maintain consistency.
 */
final class CustomerReassignmentService
{
    /**
     * Bulk-reassign a list of customers from one seller to another.
     *
     * @param  array<int, string>  $customerIds  UUID strings of customers to reassign
     *
     * @throws ValidationException  when director gate fails or seller validation fails
     */
    public function bulkReassign(
        User $fromVendedor,
        array $customerIds,
        User $toVendedor,
        ?User $director,
    ): int {
        $this->assertDirector($director);
        $this->assertSellerOrDirectorWithSell($fromVendedor);
        $this->assertSellerOrDirectorWithSell($toVendedor);

        if ($customerIds === []) {
            return 0;
        }

        $reassigned = 0;

        DB::transaction(function () use ($fromVendedor, $customerIds, $toVendedor, $director, &$reassigned): void {
            $customers = Customer::query()
                ->whereIn('id', $customerIds)
                ->where('assigned_seller_id', $fromVendedor->id)
                ->lockForUpdate()
                ->get();

            foreach ($customers as $customer) {
                $customer->assigned_seller_id = $toVendedor->id;
                // AuditObserver's updating() hook records the before/after
                // state including who triggered the change. The observer reads
                // auth()->user(), so the Director must be authenticated when
                // calling this service from a web context.
                $customer->save();
                $reassigned++;
            }
        });

        return $reassigned;
    }

    /**
     * Reassign a single customer.
     *
     * @throws ValidationException
     */
    public function reassignSingle(
        Customer $customer,
        User $toVendedor,
        ?User $director,
    ): void {
        $this->assertDirector($director);
        $this->assertSellerOrDirectorWithSell($toVendedor);

        DB::transaction(function () use ($customer, $toVendedor): void {
            $customer->lockForUpdate();
            $customer->assigned_seller_id = $toVendedor->id;
            $customer->save();
        });
    }

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    /**
     * @throws ValidationException
     */
    private function assertDirector(?User $user): void
    {
        if ($user === null || $user->role !== UserRole::Director) {
            throw ValidationException::withMessages([
                'director' => ['Solo los Directores pueden realizar reasignaciones masivas de clientes.'],
            ]);
        }
    }

    /**
     * fromVendedor / toVendedor must be a Seller OR a Director with can_sell.
     *
     * @throws ValidationException
     */
    private function assertSellerOrDirectorWithSell(User $user): void
    {
        $isSeller            = $user->role === UserRole::Seller;
        $isDirectorWithSell  = $user->role === UserRole::Director && $user->can_sell;

        if (! $isSeller && ! $isDirectorWithSell) {
            throw ValidationException::withMessages([
                'seller' => ["El usuario {$user->full_name} no tiene cartera de clientes asignable."],
            ]);
        }
    }
}
