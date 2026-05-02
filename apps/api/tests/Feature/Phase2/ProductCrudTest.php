<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Product CRUD + RLS Tests  (Phase 2 — §5.1, §16.4)
|--------------------------------------------------------------------------
|
| Verifies:
|   - Director can create and list products.
|   - Seller can list products (reference data needed for sales).
|   - Seller cannot create products (RLS WITH CHECK rejects director-only writes).
|   - Distributor can list products.
*/

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function setRlsProductContext(string $userId, string $role): void
{
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $userId));
    DB::statement(sprintf("SET LOCAL app.user_role = '%s'", $role));
}

// ---------------------------------------------------------------------------
// Director CRUD
// ---------------------------------------------------------------------------

it('Director can create a product via Eloquent', function (): void {
    $director = User::factory()->director()->create();

    DB::transaction(function () use ($director): void {
        setRlsProductContext($director->id, 'director');

        $product = Product::create([
            'name'                => 'TestProduct ' . uniqid(),
            'units_per_box'       => 5,
            'base_price_amount'   => '750.0000',
            'base_price_currency' => 'USD',
            'is_active'           => true,
        ]);

        expect($product->id)->toBeUuid();
        expect($product->name)->toStartWith('TestProduct');
        expect($product->units_per_box)->toBe(5);
    });
});

it('Director can list all products', function (): void {
    $director = User::factory()->director()->create();

    // Create two products as director (setUp has director GUC active).
    Product::create(['name' => 'Dermal-test-' . uniqid(), 'units_per_box' => 5, 'base_price_amount' => '750', 'base_price_currency' => 'USD', 'is_active' => true]);
    Product::create(['name' => 'Pink-test-' . uniqid(), 'units_per_box' => 5, 'base_price_amount' => '750', 'base_price_currency' => 'USD', 'is_active' => true]);

    DB::transaction(function () use ($director): void {
        setRlsProductContext($director->id, 'director');

        $products = Product::all();
        expect($products->count())->toBeGreaterThanOrEqual(2);
    });
});

it('Director can update a product', function (): void {
    $director = User::factory()->director()->create();
    $product  = Product::create([
        'name'                => 'UpdateMe ' . uniqid(),
        'units_per_box'       => 5,
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
        'is_active'           => true,
    ]);

    DB::transaction(function () use ($director, $product): void {
        setRlsProductContext($director->id, 'director');

        $product->update(['base_price_amount' => '800.0000']);
        $refreshed = Product::find($product->id);

        expect((string) $refreshed->base_price_amount)->toBe('800.0000');
    });
});

// ---------------------------------------------------------------------------
// Seller read access
// ---------------------------------------------------------------------------

it('Seller can list products (read-all RLS policy)', function (): void {
    $seller = User::factory()->seller()->create();

    // Products were created in director context (setUp GUC default).
    Product::create(['name' => 'SellerRead ' . uniqid(), 'units_per_box' => 5, 'base_price_amount' => '750', 'base_price_currency' => 'USD', 'is_active' => true]);

    DB::transaction(function () use ($seller): void {
        setRlsProductContext($seller->id, 'seller');

        $products = Product::all();
        expect($products->count())->toBeGreaterThanOrEqual(1);
    });
});

// ---------------------------------------------------------------------------
// Seller write denial (RLS WITH CHECK)
// ---------------------------------------------------------------------------

it('Seller cannot INSERT a product due to RLS director_write policy', function (): void {
    $seller = User::factory()->seller()->create();

    DB::transaction(function () use ($seller): void {
        setRlsProductContext($seller->id, 'seller');

        expect(function (): void {
            Product::create([
                'name'                => 'Illegal ' . uniqid(),
                'units_per_box'       => 5,
                'base_price_amount'   => '750',
                'base_price_currency' => 'USD',
                'is_active'           => true,
            ]);
        })->toThrow(\Illuminate\Database\QueryException::class);
    });
});

// ---------------------------------------------------------------------------
// Distributor read access
// ---------------------------------------------------------------------------

it('Distributor can list products (read-all RLS policy)', function (): void {
    $distributor = User::factory()->distributor()->create();

    Product::create(['name' => 'DistribRead ' . uniqid(), 'units_per_box' => 5, 'base_price_amount' => '750', 'base_price_currency' => 'USD', 'is_active' => true]);

    DB::transaction(function () use ($distributor): void {
        setRlsProductContext($distributor->id, 'distributor');

        $products = Product::all();
        expect($products->count())->toBeGreaterThanOrEqual(1);
    });
});

// ---------------------------------------------------------------------------
// UNIQUE constraint on name
// ---------------------------------------------------------------------------

it('inserting a duplicate product name raises a unique violation', function (): void {
    $name = 'Dermal-unique-' . uniqid();

    Product::create([
        'name'                => $name,
        'units_per_box'       => 5,
        'base_price_amount'   => '750',
        'base_price_currency' => 'USD',
        'is_active'           => true,
    ]);

    expect(function () use ($name): void {
        Product::create([
            'name'                => $name,
            'units_per_box'       => 5,
            'base_price_amount'   => '750',
            'base_price_currency' => 'USD',
            'is_active'           => true,
        ]);
    })->toThrow(\Illuminate\Database\QueryException::class);
});
