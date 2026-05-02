<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CustomerReassignmentService — applies a single customer reassignment
 * as proposed by the AI or by a Director manually (Phase 13 — §11.4, §3.11).
 *
 * Rules from §3.11:
 *   - Only Directors may reassign globally.
 *   - The new seller must be an active Seller (or Director with can_sell).
 *   - The operation is wrapped in a transaction and appends an audit entry
 *     to the customer's audit trail via the Auditable trait.
 */
final class CustomerReassignmentService
{
    /**
     * Reassign a single customer to a new seller.
     *
     * @throws RuntimeException When the caller is not a Director, the customer
     *                          is not found, or the target seller is ineligible.
     */
    public function reassignSingle(
        string $customerId,
        string $newSellerId,
        User $director,
        string $reason = '',
    ): Customer {
        if (! ($director->role instanceof UserRole) || ! $director->role->isDirector()) {
            throw new RuntimeException('Only Directors may reassign customers.');
        }

        return DB::transaction(function () use ($customerId, $newSellerId, $director, $reason): Customer {
            /** @var Customer $customer */
            $customer = Customer::where('id', $customerId)->lockForUpdate()->firstOrFail();

            $newSeller = User::where('id', $newSellerId)
                ->where('is_active', true)
                ->whereIn('role', [UserRole::Seller->value, UserRole::Director->value])
                ->first();

            if ($newSeller === null) {
                throw new RuntimeException(
                    "Target seller [{$newSellerId}] not found or ineligible for assignment."
                );
            }

            if ($newSeller->role === UserRole::Director && ! $newSeller->can_sell) {
                throw new RuntimeException(
                    "Director [{$newSellerId}] does not have the 'can_sell' flag — cannot be assigned as seller."
                );
            }

            $previousSellerId = $customer->assigned_seller_id;

            $customer->update([
                'assigned_seller_id' => $newSellerId,
            ]);

            // Append a structured note to the audit trail for traceability.
            // The Auditable trait records the update; we also log a richer
            // entry directly so Directors can filter "AI-suggested" events.
            DB::table('audit_logs')->insert([
                'id'          => \Illuminate\Support\Str::uuid()->toString(),
                'user_id'     => $director->getKey(),
                'action'      => 'customer.reassigned',
                'entity_type' => 'customer',
                'entity_id'   => $customer->getKey(),
                'old_values'  => json_encode(['assigned_seller_id' => $previousSellerId]),
                'new_values'  => json_encode(['assigned_seller_id' => $newSellerId]),
                'notes'       => $reason ?: 'AI-suggested reassignment via §11.4',
                'created_at'  => now(),
            ]);

            return $customer->refresh()->load(['assignedSeller', 'zone']);
        });
    }
}
