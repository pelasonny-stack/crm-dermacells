<?php

declare(strict_types=1);

use App\Models\CentralStock;
use App\Models\DistributorStock;
use App\Models\Product;
use App\Models\SellerStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 4 — RLS visibility tests for stock tables
|--------------------------------------------------------------------------
|
| Validates that the RLS policies defined in migration 000006 correctly
| segment access:
|
|  Table             | Director | Distributor | Seller
|  ----------------- | -------- | ----------- | ------
|  central_stock     | YES      | NO          | NO
|  distributor_stock | YES      | own only    | NO
|  seller_stock      | YES      | (zone, P4)  | own only
|
*/

// Helpers ------------------------------------------------------------------

function seedCentralStock(string $productId): CentralStock
{
    return CentralStock::create([
        'product_id'       => $productId,
        'total_imported'   => 10,
        'total_dispatched' => 0,
        'minimum_stock'    => 5,
    ]);
}

function seedDistributorStock(string $distributorId, string $productId, int $available = 8): DistributorStock
{
    return DistributorStock::create([
        'distributor_id'      => $distributorId,
        'product_id'          => $productId,
        'total_received'      => $available,
        'total_redistributed' => 0,
        'reserved'            => 0,
        'available'           => $available,
        'minimum_stock'       => 2,
    ]);
}

function seedSellerStock(string $sellerId, string $productId, int $boxes = 4): SellerStock
{
    return SellerStock::create([
        'seller_id'     => $sellerId,
        'product_id'    => $productId,
        'boxes'         => $boxes,
        'loose_units'   => 0,
        'reserved_boxes' => 0,
        'reserved_units' => 0,
        'minimum_stock' => 1,
    ]);
}

// ---------------------------------------------------------------------------
// Central stock — Director only
// ---------------------------------------------------------------------------

it('Director can see central_stock rows', function (): void {
    $director = User::factory()->director()->create();
    $product  = Product::factory()->create();

    seedCentralStock($product->id);

    DB::transaction(function () use ($director): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
        DB::statement("SET LOCAL app.user_role = 'director'");

        expect(CentralStock::count())->toBeGreaterThanOrEqual(1);
    });
});

it('Distributor cannot see central_stock rows', function (): void {
    $distributor = User::factory()->distributor()->create();
    $product     = Product::factory()->create();

    seedCentralStock($product->id);

    DB::transaction(function () use ($distributor): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $distributor->id));
        DB::statement("SET LOCAL app.user_role = 'distributor'");

        expect(CentralStock::count())->toBe(0);
    });
});

it('Seller cannot see central_stock rows', function (): void {
    $seller  = User::factory()->seller()->create();
    $product = Product::factory()->create();

    seedCentralStock($product->id);

    DB::transaction(function () use ($seller): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $seller->id));
        DB::statement("SET LOCAL app.user_role = 'seller'");

        expect(CentralStock::count())->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// GET /v1/stock/central — HTTP level
// ---------------------------------------------------------------------------

it('GET /v1/stock/central returns 200 for Director', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/stock/central');

    $response->assertOk();
});

it('GET /v1/stock/central returns 403 for Distributor', function (): void {
    $distributor = User::factory()->distributor()->create();

    $response = $this->actingAs($distributor, 'sanctum')
        ->getJson('/api/v1/stock/central');

    $response->assertForbidden();
});

it('GET /v1/stock/central returns 403 for Seller', function (): void {
    $seller = User::factory()->seller()->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/stock/central');

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// Distributor stock — RLS isolation between distributors
// ---------------------------------------------------------------------------

it('Director can see all distributor_stock rows', function (): void {
    $director     = User::factory()->director()->create();
    $distributorA = User::factory()->distributor()->create();
    $distributorB = User::factory()->distributor()->create();
    $product      = Product::factory()->create();

    seedDistributorStock($distributorA->id, $product->id, 5);
    seedDistributorStock($distributorB->id, $product->id, 3);

    DB::transaction(function () use ($director): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
        DB::statement("SET LOCAL app.user_role = 'director'");

        expect(DistributorStock::count())->toBeGreaterThanOrEqual(2);
    });
});

it('Distributor A only sees own distributor_stock rows, not Distributor B rows', function (): void {
    $distributorA = User::factory()->distributor()->create();
    $distributorB = User::factory()->distributor()->create();
    $product      = Product::factory()->create();

    seedDistributorStock($distributorA->id, $product->id, 5);
    seedDistributorStock($distributorB->id, $product->id, 3);

    DB::transaction(function () use ($distributorA, $distributorB): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $distributorA->id));
        DB::statement("SET LOCAL app.user_role = 'distributor'");

        $visible = DistributorStock::all();

        expect($visible)->toHaveCount(1);
        expect($visible->first()->distributor_id)->toBe($distributorA->id);
        expect($visible->pluck('distributor_id'))->not->toContain($distributorB->id);
    });
});

it('Seller cannot see any distributor_stock rows', function (): void {
    $seller      = User::factory()->seller()->create();
    $distributor = User::factory()->distributor()->create();
    $product     = Product::factory()->create();

    seedDistributorStock($distributor->id, $product->id, 5);

    DB::transaction(function () use ($seller): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $seller->id));
        DB::statement("SET LOCAL app.user_role = 'seller'");

        expect(DistributorStock::count())->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Seller stock — RLS isolation
// ---------------------------------------------------------------------------

it('Director can see all seller_stock rows', function (): void {
    $director = User::factory()->director()->create();
    $sellerA  = User::factory()->seller()->create();
    $sellerB  = User::factory()->seller()->create();
    $product  = Product::factory()->create();

    seedSellerStock($sellerA->id, $product->id, 4);
    seedSellerStock($sellerB->id, $product->id, 2);

    DB::transaction(function () use ($director): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
        DB::statement("SET LOCAL app.user_role = 'director'");

        expect(SellerStock::count())->toBeGreaterThanOrEqual(2);
    });
});

it('Seller A only sees own seller_stock row, not Seller B row', function (): void {
    $sellerA = User::factory()->seller()->create();
    $sellerB = User::factory()->seller()->create();
    $product = Product::factory()->create();

    seedSellerStock($sellerA->id, $product->id, 4);
    seedSellerStock($sellerB->id, $product->id, 2);

    DB::transaction(function () use ($sellerA, $sellerB): void {
        DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $sellerA->id));
        DB::statement("SET LOCAL app.user_role = 'seller'");

        $visible = SellerStock::all();

        expect($visible)->toHaveCount(1);
        expect($visible->first()->seller_id)->toBe($sellerA->id);
        expect($visible->pluck('seller_id'))->not->toContain($sellerB->id);
    });
});

it('GET /v1/stock/seller returns only own rows for Seller', function (): void {
    $sellerA = User::factory()->seller()->create();
    $sellerB = User::factory()->seller()->create();
    $product = Product::factory()->create();

    DB::statement("SET LOCAL app.user_role = 'director'");
    DB::statement("SET LOCAL app.user_id = '00000000-0000-0000-0000-000000000000'");

    seedSellerStock($sellerA->id, $product->id, 4);
    seedSellerStock($sellerB->id, $product->id, 2);

    $response = $this->actingAs($sellerA, 'sanctum')
        ->getJson('/api/v1/stock/seller');

    $response->assertOk();
    $sellerIds = collect($response->json('data'))->pluck('seller_id')->unique()->values()->all();
    expect($sellerIds)->toHaveCount(1);
    expect($sellerIds[0])->toBe($sellerA->id);
});

it('GET /v1/stock/distributor returns 403 for Seller', function (): void {
    $seller = User::factory()->seller()->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/stock/distributor');

    $response->assertForbidden();
});
