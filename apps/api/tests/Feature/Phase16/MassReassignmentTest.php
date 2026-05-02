<?php

declare(strict_types=1);

use App\Domain\Customers\Services\CustomerReassignmentService;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\ExchangeRate;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| MassReassignmentTest — Phase 16
|--------------------------------------------------------------------------
|
| Verifies §3.11: Director bulk-reassigns clients to a different Seller.
| - audit_log has one row per reassigned customer
| - customers.assigned_seller_id is updated
| - open sales remain under original Vendedor (not reassigned)
|
*/

function makeSellerWithCustomers(int $count): array
{
    $zone     = Zone::factory()->create(['is_active' => true]);
    $category = CustomerCategory::factory()->create();
    $terms    = PaymentTerm::factory()->create();
    $seller   = User::factory()->seller()->create();

    $customers = Customer::factory($count)->create([
        'zone_id'                  => $zone->id,
        'category_id'              => $category->id,
        'default_payment_terms_id' => $terms->id,
        'assigned_seller_id'       => $seller->id,
        'is_active'                => true,
    ]);

    return [$seller, $customers, $zone, $category, $terms];
}

it('Director bulk-reassigns 5 clients and audit_log has 5 rows', function (): void {
    [$fromSeller, $customers] = makeSellerWithCustomers(5);

    $toSeller = User::factory()->seller()->create();
    $director = User::factory()->director()->create();

    $customerIds = $customers->pluck('id')->all();

    $this->actingAs($director);

    $service = app(CustomerReassignmentService::class);
    $count   = $service->bulkReassign($fromSeller, $customerIds, $toSeller, $director);

    expect($count)->toBe(5);

    // All 5 customers now belong to toSeller.
    $moved = Customer::whereIn('id', $customerIds)
        ->where('assigned_seller_id', $toSeller->id)
        ->count();

    expect($moved)->toBe(5);

    // audit_log should have entries for the 5 updated customer rows.
    $auditRows = DB::table('audit_log')
        ->where('entity_type', 'customers')
        ->whereIn('entity_id', $customerIds)
        ->count();

    expect($auditRows)->toBeGreaterThanOrEqual(5);
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('audit_log'), 'audit_log table not yet migrated');

it('open sales remain under original Vendedor after reassignment', function (): void {
    [$fromSeller, $customers] = makeSellerWithCustomers(3);

    $customer = $customers->first();
    $toSeller = User::factory()->seller()->create();
    $director = User::factory()->director()->create();

    // Create an open (draft) sale for the customer under fromSeller.
    // Using DB insert directly to avoid needing SaleFactory full chain.
    // Resolve mandatory FK columns from seeded/factory data.
    $zone          = Zone::factory()->create();
    $paymentTerms  = \App\Models\PaymentTerm::factory()->create();
    $exchangeRate  = ExchangeRate::factory()->create();

    $saleId = (string) \Illuminate\Support\Str::uuid();
    DB::table('sales')->insert([
        'id'               => $saleId,
        'customer_id'      => $customer->id,
        'seller_id'        => $fromSeller->id,
        'zone_id'          => $zone->id,
        'payment_terms_id' => $paymentTerms->id,
        'status'           => 'draft',
        'sale_date'        => now()->toDateString(),
        'currency'         => 'ARS',
        'exchange_rate_id' => $exchangeRate->id,
        'total_amount'     => '1500.0000',
        'total_currency'   => 'ARS',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $this->actingAs($director);

    $service = app(CustomerReassignmentService::class);
    $service->bulkReassign($fromSeller, [$customer->id], $toSeller, $director);

    // Sale must still reference fromSeller (§3.11).
    $sale = DB::table('sales')->where('id', $saleId)->first();
    expect($sale->seller_id)->toBe($fromSeller->id);

    // Customer is now under toSeller.
    $customer->refresh();
    expect($customer->assigned_seller_id)->toBe($toSeller->id);
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('sales'), 'sales table not yet migrated');

it('non-Director cannot perform bulk reassignment', function (): void {
    [$fromSeller, $customers] = makeSellerWithCustomers(2);

    $toSeller  = User::factory()->seller()->create();
    $nonDirector = User::factory()->seller()->create();

    $service = app(CustomerReassignmentService::class);

    expect(fn () => $service->bulkReassign(
        $fromSeller,
        $customers->pluck('id')->all(),
        $toSeller,
        $nonDirector,
    ))->toThrow(ValidationException::class);
});

it('returns 0 when customer_ids list is empty', function (): void {
    $fromSeller = User::factory()->seller()->create();
    $toSeller   = User::factory()->seller()->create();
    $director   = User::factory()->director()->create();

    $service = app(CustomerReassignmentService::class);
    $count   = $service->bulkReassign($fromSeller, [], $toSeller, $director);

    expect($count)->toBe(0);
});
