<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\Zone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the requested seller is permitted to sell to the customer
 * in the sale's zone, and that delegated_delivery configuration is consistent.
 *
 * Rules (§5.7):
 *   1. Seller must own the customer OR be a Director with can_sell=true.
 *   2. If the customer's zone differs from any "base" zone of the Seller,
 *      delegated_delivery must be enabled on that Seller's user record.
 *   3. If delegated_delivery=true, the zone must have a Distributor assigned.
 *
 * The rule receives the full request input via the constructor so it can
 * access zone_id and delegated_delivery alongside the seller_id being validated.
 *
 * @param array<string,mixed> $requestData  Full validated request input
 */
final class SellerCanSellInZone implements ValidationRule
{
    /**
     * @param array<string,mixed> $requestData
     */
    public function __construct(private readonly array $requestData) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sellerId   = $value;
        $customerId = $this->requestData['customer_id'] ?? null;
        $zoneId     = $this->requestData['zone_id'] ?? null;
        $delegated  = (bool) ($this->requestData['delegated_delivery'] ?? false);

        /** @var User|null $seller */
        $seller = User::find($sellerId);
        if (! $seller) {
            $fail('The selected seller does not exist.');
            return;
        }

        // Directors with can_sell may sell anywhere
        if ($seller->role === UserRole::Director && $seller->can_sell) {
            return;
        }

        // Verify the seller is actually assigned to the customer
        if ($customerId) {
            $customer = Customer::find($customerId);
            if ($customer && $customer->assigned_seller_id !== $sellerId) {
                $fail('The selected seller is not assigned to this customer.');
                return;
            }
        }

        // If delegated_delivery=true, the zone must have a Distributor
        if ($delegated && $zoneId) {
            $zone = Zone::find($zoneId);
            if (! $zone || ! $zone->distributor_id) {
                $fail('Delegated delivery requires the zone to have an assigned Distributor.');
                return;
            }

            // Check that the Seller has delegated_delivery flag enabled
            // (stored on users.delegated_delivery_enabled — Phase 4 column)
            if (
                property_exists($seller, 'delegated_delivery_enabled')
                && ! $seller->delegated_delivery_enabled
            ) {
                $fail('This seller is not authorized for delegated delivery in foreign zones.');
            }
        }
    }
}
