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
| Phase 3 — Category D (director_only) Protection Tests
|--------------------------------------------------------------------------
|
| §3.3 + §2.4: Category D customers are invisible to Sellers and Distributors.
| The RLS policies in migration 000005 include a category_id NOT IN
| (SELECT id FROM customer_categories WHERE director_only = TRUE) predicate
| in both the seller and distributor USING clauses.
|
| This test file verifies that category D rows are silently excluded from
| Sellers and Distributors, and fully accessible to Directors.
*/

// ---------------------------------------------------------------------------
// 1. Seller cannot see category D customers
// ---------------------------------------------------------------------------

it('Seller cannot see a category D customer (RLS silently excludes it)', function (): void {
    $seller      = User::factory()->seller()->create();
    $distributor = User::factory()->distributor()->create();

    $catD  = CustomerCategory::factory()->categoryD()->create();
    $catA  = CustomerCategory::factory()->create();
    $zone  = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $terms = PaymentTerm::factory()->create();

    $catDCustomer = Customer::factory()
        ->assignedTo($seller)
        ->inZone($zone)
        ->create([
            'category_id'              => $catD->id,
            'default_payment_terms_id' => $terms->id,
        ]);

    $regularCustomer = Customer::factory()
        ->assignedTo($seller)
        ->inZone($zone)
        ->create([
            'category_id'              => $catA->id,
            'default_payment_terms_id' => $terms->id,
        ]);

    DB::transaction(function () use ($seller, $catDCustomer, $regularCustomer): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $seller->id));
        DB::statement("SET LOCAL app.user_role = 'seller'");

        $visible = Customer::all()->pluck('id');

        // Regular customer is visible
        expect($visible)->toContain($regularCustomer->id);
        // Category D customer is NOT visible to the Seller
        expect($visible)->not->toContain($catDCustomer->id);
    });
});

it('Seller receives 200 with empty list for category D customer via API show (RLS returns 404)', function (): void {
    $seller      = User::factory()->seller()->create();
    $distributor = User::factory()->distributor()->create();

    $catD  = CustomerCategory::factory()->categoryD()->create();
    $zone  = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $terms = PaymentTerm::factory()->create();

    $catDCustomer = Customer::factory()
        ->assignedTo($seller)
        ->inZone($zone)
        ->create([
            'category_id'              => $catD->id,
            'default_payment_terms_id' => $terms->id,
        ]);

    // RLS hides the row — route model binding fails → 404
    $response = $this->actingAs($seller, 'sanctum')
        ->getJson("/api/v1/customers/{$catDCustomer->id}");

    $response->assertNotFound();
});

// ---------------------------------------------------------------------------
// 2. Distributor cannot see category D customers
// ---------------------------------------------------------------------------

it('Distributor cannot see a category D customer in their zone (RLS)', function (): void {
    $distributor = User::factory()->distributor()->create();
    $seller      = User::factory()->seller()->create();

    $catD  = CustomerCategory::factory()->categoryD()->create();
    $zone  = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $terms = PaymentTerm::factory()->create();

    $catDCustomer = Customer::factory()
        ->assignedTo($seller)
        ->inZone($zone)
        ->create([
            'category_id'              => $catD->id,
            'default_payment_terms_id' => $terms->id,
        ]);

    DB::transaction(function () use ($distributor, $catDCustomer): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $distributor->id));
        DB::statement("SET LOCAL app.user_role = 'distributor'");

        $visible = Customer::all()->pluck('id');
        expect($visible)->not->toContain($catDCustomer->id);
    });
});

// ---------------------------------------------------------------------------
// 3. Director can see category D customers
// ---------------------------------------------------------------------------

it('Director sees category D customers (full access)', function (): void {
    $director    = User::factory()->director()->create();
    $seller      = User::factory()->seller()->create();
    $distributor = User::factory()->distributor()->create();

    $catD  = CustomerCategory::factory()->categoryD()->create();
    $zone  = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $terms = PaymentTerm::factory()->create();

    $catDCustomer = Customer::factory()
        ->assignedTo($seller)
        ->inZone($zone)
        ->create([
            'category_id'              => $catD->id,
            'default_payment_terms_id' => $terms->id,
        ]);

    DB::transaction(function () use ($director, $catDCustomer): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
        DB::statement("SET LOCAL app.user_role = 'director'");

        $visible = Customer::all()->pluck('id');
        expect($visible)->toContain($catDCustomer->id);
    });
});

it('Director can retrieve a category D customer via API', function (): void {
    $director    = User::factory()->director()->create();
    $seller      = User::factory()->seller()->create();
    $distributor = User::factory()->distributor()->create();

    $catD  = CustomerCategory::factory()->categoryD()->create();
    $zone  = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $terms = PaymentTerm::factory()->create();

    $catDCustomer = Customer::factory()
        ->assignedTo($seller)
        ->inZone($zone)
        ->create([
            'category_id'              => $catD->id,
            'default_payment_terms_id' => $terms->id,
        ]);

    $response = $this->actingAs($director, 'sanctum')
        ->getJson("/api/v1/customers/{$catDCustomer->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $catDCustomer->id);
});

// ---------------------------------------------------------------------------
// 4. Seller cannot create a category D customer
// ---------------------------------------------------------------------------

it('Seller cannot create a category D customer (WITH CHECK blocks the INSERT)', function (): void {
    $seller      = User::factory()->seller()->create();
    $distributor = User::factory()->distributor()->create();

    $catD  = CustomerCategory::factory()->categoryD()->create();
    $zone  = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $terms = PaymentTerm::factory()->create();

    // The RLS WITH CHECK predicate will cause the INSERT to silently fail
    // or raise a policy violation. Via the API this surfaces as a 500 or the
    // row is invisible post-insert. Verify the row is not visible after attempt.
    expect(function () use ($seller, $catD, $zone, $terms): void {
        DB::transaction(function () use ($seller, $catD, $zone, $terms): void {
            DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $seller->id));
            DB::statement("SET LOCAL app.user_role = 'seller'");

            Customer::create([
                'first_name'               => 'Test',
                'last_name'                => 'CatD',
                'cuit'                     => '30123456789',
                'phone'                    => '+54911',
                'email'                    => 'catd@test.com',
                'address'                  => 'Test addr',
                'category_id'              => $catD->id,
                'zone_id'                  => $zone->id,
                'assigned_seller_id'       => $seller->id,
                'default_payment_terms_id' => $terms->id,
                'is_active'                => true,
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});
