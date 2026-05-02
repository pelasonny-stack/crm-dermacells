<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 3 — RLS Customer Visibility Tests
|--------------------------------------------------------------------------
|
| Validates the Postgres RLS policies defined in migration 000005:
|
|   Seller: sees only customers where assigned_seller_id = app.user_id
|   Distributor: sees customers in zone where zones.distributor_id = app.user_id
|   Director: sees all customers
|
| Tests use nested DB::transaction blocks (savepoints) to override the GUCs
| per assertion, then roll back automatically via DatabaseTransactions in Pest.
|
| All factory inserts run under director GUCs (set by TestCase::setUp) so
| they pass the WITH CHECK predicates.
*/

function setGuc(string $userId, string $role): void
{
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $userId));
    DB::statement(sprintf("SET LOCAL app.user_role = '%s'", $role));
}

function makeBasicCustomer(User $seller, Zone $zone, CustomerCategory $cat, PaymentTerm $terms): Customer
{
    return Customer::factory()
        ->assignedTo($seller)
        ->inZone($zone)
        ->create([
            'category_id'              => $cat->id,
            'default_payment_terms_id' => $terms->id,
        ]);
}

// ---------------------------------------------------------------------------
// 1. Seller scope
// ---------------------------------------------------------------------------

it('Seller only sees their own assigned customers (RLS)', function (): void {
    $sellerA = User::factory()->seller()->create();
    $sellerB = User::factory()->seller()->create();

    $zone  = Zone::factory()->create(['distributor_id' => User::factory()->distributor()->create()->id]);
    $cat   = CustomerCategory::factory()->create();
    $terms = PaymentTerm::factory()->create();

    $customerA = makeBasicCustomer($sellerA, $zone, $cat, $terms);
    $customerB = makeBasicCustomer($sellerB, $zone, $cat, $terms);

    DB::transaction(function () use ($sellerA, $customerA, $customerB): void {
        setGuc($sellerA->id, 'seller');

        $visible = Customer::all()->pluck('id');

        expect($visible)->toContain($customerA->id);
        expect($visible)->not->toContain($customerB->id);
    });
});

it('Seller A cannot see Seller B customers via API', function (): void {
    $sellerA = User::factory()->seller()->create();
    $sellerB = User::factory()->seller()->create();

    $zone  = Zone::factory()->create(['distributor_id' => User::factory()->distributor()->create()->id]);
    $cat   = CustomerCategory::factory()->create();
    $terms = PaymentTerm::factory()->create();

    makeBasicCustomer($sellerA, $zone, $cat, $terms);
    $customerB = makeBasicCustomer($sellerB, $zone, $cat, $terms);

    // Acting as sellerA via HTTP — middleware sets GUCs to seller scope
    $response = $this->actingAs($sellerA, 'sanctum')
        ->getJson('/api/v1/customers');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain($customerB->id);
});

// ---------------------------------------------------------------------------
// 2. Distributor scope
// ---------------------------------------------------------------------------

it('Distributor sees customers in their zone only (RLS)', function (): void {
    $distributor = User::factory()->distributor()->create();
    $sellerA     = User::factory()->seller()->create();
    $sellerB     = User::factory()->seller()->create();

    $zoneWithDistributor    = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $zoneWithoutDistributor = Zone::factory()->directZone()->create();

    $cat   = CustomerCategory::factory()->create();
    $terms = PaymentTerm::factory()->create();

    $customerInZone    = makeBasicCustomer($sellerA, $zoneWithDistributor, $cat, $terms);
    $customerOutOfZone = makeBasicCustomer($sellerB, $zoneWithoutDistributor, $cat, $terms);

    DB::transaction(function () use ($distributor, $customerInZone, $customerOutOfZone): void {
        setGuc($distributor->id, 'distributor');

        $visible = Customer::all()->pluck('id');

        expect($visible)->toContain($customerInZone->id);
        expect($visible)->not->toContain($customerOutOfZone->id);
    });
});

it('Distributor cannot see customers in another distributor zone', function (): void {
    $distributorA = User::factory()->distributor()->create();
    $distributorB = User::factory()->distributor()->create();

    $zoneA = Zone::factory()->create(['distributor_id' => $distributorA->id]);
    $zoneB = Zone::factory()->create(['distributor_id' => $distributorB->id]);

    $seller = User::factory()->seller()->create();
    $cat    = CustomerCategory::factory()->create();
    $terms  = PaymentTerm::factory()->create();

    $customerInZoneB = makeBasicCustomer($seller, $zoneB, $cat, $terms);

    DB::transaction(function () use ($distributorA, $customerInZoneB): void {
        setGuc($distributorA->id, 'distributor');

        $visible = Customer::all()->pluck('id');
        expect($visible)->not->toContain($customerInZoneB->id);
    });
});

// ---------------------------------------------------------------------------
// 3. Director scope
// ---------------------------------------------------------------------------

it('Director sees all customers across all zones and sellers', function (): void {
    $director    = User::factory()->director()->create();
    $sellerA     = User::factory()->seller()->create();
    $sellerB     = User::factory()->seller()->create();
    $distributor = User::factory()->distributor()->create();

    $zoneA = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $zoneB = Zone::factory()->directZone()->create();

    $cat   = CustomerCategory::factory()->create();
    $terms = PaymentTerm::factory()->create();

    $customerA = makeBasicCustomer($sellerA, $zoneA, $cat, $terms);
    $customerB = makeBasicCustomer($sellerB, $zoneB, $cat, $terms);

    DB::transaction(function () use ($director, $customerA, $customerB): void {
        setGuc($director->id, 'director');

        $visible = Customer::all()->pluck('id');

        expect($visible)->toContain($customerA->id);
        expect($visible)->toContain($customerB->id);
    });
});

it('Director sees all customers via API', function (): void {
    $director = User::factory()->director()->create();
    $sellerA  = User::factory()->seller()->create();
    $sellerB  = User::factory()->seller()->create();

    $zone  = Zone::factory()->create(['distributor_id' => User::factory()->distributor()->create()->id]);
    $cat   = CustomerCategory::factory()->create();
    $terms = PaymentTerm::factory()->create();

    $c1 = makeBasicCustomer($sellerA, $zone, $cat, $terms);
    $c2 = makeBasicCustomer($sellerB, $zone, $cat, $terms);

    $response = $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/customers?active_only=false');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($c1->id);
    expect($ids)->toContain($c2->id);
});
