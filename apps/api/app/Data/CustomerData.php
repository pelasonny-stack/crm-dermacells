<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Customer;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * DTO for Customer responses — used in all CustomerController endpoints.
 *
 * PERMISSIONS META BLOCK (per Phase 0 spec)
 * ==========================================
 * The `permissions` property carries per-resource capabilities for the
 * requesting user. The controller populates this after resolving the user's
 * role and the customer's ownership context.
 *
 * Including permissions in the DTO response removes the need for a separate
 * `/permissions` endpoint and lets the frontend/mobile client render action
 * buttons conditionally without a second round-trip.
 *
 * MONEY SERIALIZATION
 * ====================
 * Brick\Money\Money does not implement JsonSerializable. We serialize it
 * manually to { amount: "750.0000", currency: "USD" } so the API surface is
 * stable regardless of Brick's internal representation.
 */
class CustomerData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $first_name,
        public readonly string $last_name,
        public readonly string $cuit,
        public readonly string $phone,
        public readonly string $email,
        public readonly string $address,
        public readonly string $category_id,
        public readonly string|null $zone_id,
        public readonly string $assigned_seller_id,
        public readonly string $default_payment_terms_id,

        /** @var array{amount: string, currency: string}|null */
        public readonly array|null $reference_price,

        /** @var array{amount: string, currency: string}|null */
        public readonly array|null $reference_price_unit,

        public readonly int|null $purchase_frequency_days,
        public readonly string|null $first_purchase_date,
        public readonly bool $is_active,
        public readonly string|null $deactivated_at,
        public readonly string|null $deactivation_reason,
        public readonly string $created_at,
        public readonly string $updated_at,

        /** @var array<string, bool>|Optional */
        #[MapOutputName('meta')]
        public readonly array|Optional $meta = new Optional,
    ) {}

    /**
     * Build from an Eloquent Customer model.
     *
     * @param array<string, bool> $permissions  e.g. ['can_edit' => true, 'can_deactivate' => false]
     */
    public static function fromModel(Customer $customer, array $permissions = []): self
    {
        $refPrice = null;
        if ($customer->reference_price_amount !== null) {
            $refPrice = [
                'amount'   => number_format((float) $customer->reference_price_amount, 4, '.', ''),
                'currency' => $customer->reference_price_currency ?? 'USD',
            ];
        }

        $unitPrice = null;
        if ($customer->reference_price_unit_amount !== null) {
            $unitPrice = [
                'amount'   => number_format((float) $customer->reference_price_unit_amount, 4, '.', ''),
                'currency' => $customer->reference_price_currency ?? 'USD',
            ];
        }

        return new self(
            id:                       $customer->id,
            first_name:               $customer->first_name,
            last_name:                $customer->last_name,
            cuit:                     $customer->cuit,
            phone:                    $customer->phone,
            email:                    $customer->email,
            address:                  $customer->address,
            category_id:              $customer->category_id,
            zone_id:                  $customer->zone_id,
            assigned_seller_id:       $customer->assigned_seller_id,
            default_payment_terms_id: $customer->default_payment_terms_id,
            reference_price:          $refPrice,
            reference_price_unit:     $unitPrice,
            purchase_frequency_days:  $customer->purchase_frequency_days,
            first_purchase_date:      $customer->first_purchase_date?->toDateString(),
            is_active:                $customer->is_active,
            deactivated_at:           $customer->deactivated_at?->toIso8601String(),
            deactivation_reason:      $customer->deactivation_reason,
            created_at:               $customer->created_at?->toIso8601String() ?? '',
            updated_at:               $customer->updated_at?->toIso8601String() ?? '',
            meta:                     empty($permissions) ? new Optional : ['permissions' => $permissions],
        );
    }
}
