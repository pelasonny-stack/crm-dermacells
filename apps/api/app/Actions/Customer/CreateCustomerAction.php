<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new customer, optionally with initial billing entities.
 *
 * This action is the single canonical path for customer creation. It:
 *   1. Creates the customer row inside a transaction.
 *   2. If billing entities are provided in the payload, creates them and
 *      ensures exactly one is marked as primary (the first one, if none is
 *      explicitly flagged).
 *   3. The transaction guarantees atomicity: either the customer + all
 *      billing entities are created, or none are.
 *
 * ANTI-PATTERNS AVOIDED
 * =====================
 * - No `$request->all()` mass-assignment — only validated, explicit fields.
 * - CUIT validation (format + uniqueness) is enforced upstream in
 *   CreateCustomerRequest. This action trusts the input is clean.
 */
class CreateCustomerAction
{
    /**
     * @param array<string, mixed>                   $data            Validated customer fields.
     * @param list<array<string, mixed>>             $billingEntities Validated billing entity payloads (optional).
     * @param User                                   $createdBy       The authenticated user initiating the action.
     */
    public function execute(array $data, array $billingEntities, User $createdBy): Customer
    {
        return DB::transaction(function () use ($data, $billingEntities, $createdBy): Customer {
            $customer = Customer::create([
                'first_name'               => $data['first_name'],
                'last_name'                => $data['last_name'],
                'cuit'                     => str_replace('-', '', $data['cuit']),
                'phone'                    => $data['phone'],
                'email'                    => $data['email'],
                'address'                  => $data['address'],
                'category_id'              => $data['category_id'],
                'zone_id'                  => $data['zone_id'],
                'assigned_seller_id'       => $data['assigned_seller_id'],
                'default_payment_terms_id' => $data['default_payment_terms_id'],
                'reference_price_amount'   => $data['reference_price_amount'] ?? null,
                'reference_price_currency' => $data['reference_price_currency'] ?? null,
                'reference_price_unit_amount' => $data['reference_price_unit_amount'] ?? null,
                'purchase_frequency_days'  => $data['purchase_frequency_days'] ?? null,
                'is_active'                => true,
            ]);

            if (! empty($billingEntities)) {
                $hasPrimary = collect($billingEntities)->contains(fn (array $e) => ($e['is_primary'] ?? false) === true);

                foreach ($billingEntities as $index => $entity) {
                    CustomerBillingEntity::create([
                        'customer_id'   => $customer->id,
                        'name'          => $entity['name'],
                        'cuit'          => str_replace('-', '', $entity['cuit']),
                        'iva_condition' => $entity['iva_condition'],
                        // Mark first entity as primary when none is explicitly flagged
                        'is_primary'    => ($entity['is_primary'] ?? false) || (! $hasPrimary && $index === 0),
                    ]);
                }
            }

            return $customer->load(['category', 'zone', 'assignedSeller', 'defaultPaymentTerm', 'billingEntities']);
        });
    }
}
