<?php

declare(strict_types=1);

use App\Actions\Stock\DispatchToDistributorAction;
use App\Actions\Stock\DispatchDirectToSellerAction;
use App\Actions\Stock\RegisterImportAction;
use App\Exceptions\StockInsufficientException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 4 — Insufficient stock dispatch tests
|--------------------------------------------------------------------------
|
| Verifies that dispatching more boxes than available throws
| StockInsufficientException and the API endpoint maps it to 422
| with business code STOCK_INSUFFICIENT.
|
*/

function importBoxes(User $director, string $productId, int $qty): void
{
    // Ensure RegisterImportAction can resolve Auth::user() for the director check.
    Auth::setUser($director);
    $action = app(RegisterImportAction::class);
    $action->execute(
        productId: $productId,
        quantityBoxes: $qty,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-' . uniqid(),
    );
}

// ---------------------------------------------------------------------------
// DispatchToDistributor — insufficient central stock
// ---------------------------------------------------------------------------

it('DispatchToDistributorAction throws StockInsufficientException when dispatching more than available', function (): void {
    $director     = User::factory()->director()->create();
    $distributor  = User::factory()->distributor()->create();
    $product      = Product::create([
        'name' => 'Pink-' . uniqid(), 'units_per_box' => 5,
        'base_price_amount' => '750.0000', 'base_price_currency' => 'USD', 'is_active' => true,
    ]);

    $this->actingAs($director, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");

    importBoxes($director, $product->id, 3);

    $action = app(DispatchToDistributorAction::class);

    expect(fn () => $action->execute(
        productId: $product->id,
        distributorId: $distributor->id,
        quantityBoxes: 10, // only 3 available
    ))->toThrow(StockInsufficientException::class);
});

it('StockInsufficientException carries correct metadata', function (): void {
    $director    = User::factory()->director()->create();
    $distributor = User::factory()->distributor()->create();
    $product     = Product::create([
        'name' => 'Capillary-' . uniqid(), 'units_per_box' => 5,
        'base_price_amount' => '750.0000', 'base_price_currency' => 'USD', 'is_active' => true,
    ]);

    $this->actingAs($director, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");

    importBoxes($director, $product->id, 2);

    $caught = null;

    try {
        $action = app(DispatchToDistributorAction::class);
        $action->execute(
            productId: $product->id,
            distributorId: $distributor->id,
            quantityBoxes: 5,
        );
    } catch (StockInsufficientException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->productId)->toBe($product->id);
    expect($caught->requested)->toBe(5);
    expect($caught->available)->toBe(2);
    expect($caught->sourceType)->toBe('central');
});

// ---------------------------------------------------------------------------
// API endpoint returns 422 with STOCK_INSUFFICIENT code
// ---------------------------------------------------------------------------

it('POST /v1/stock/central/dispatches returns 422 STOCK_INSUFFICIENT when over-dispatching to distributor', function (): void {
    $director    = User::factory()->director()->create();
    $distributor = User::factory()->distributor()->create();
    $product     = Product::create([
        'name' => 'Biomask-' . uniqid(), 'units_per_box' => 5,
        'base_price_amount' => '750.0000', 'base_price_currency' => 'USD', 'is_active' => true,
    ]);

    // Import only 2 boxes via action (bypasses HTTP to avoid nesting issues)
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");
    importBoxes($director, $product->id, 2);

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/stock/central/dispatches', [
            'product_id'       => $product->id,
            'destination_type' => 'distributor',
            'destination_id'   => $distributor->id,
            'quantity_boxes'   => 5,
        ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'STOCK_INSUFFICIENT');
    $response->assertJsonPath('meta.available', 2);
    $response->assertJsonPath('meta.requested', 5);
});

it('POST /v1/stock/central/dispatches returns 422 STOCK_INSUFFICIENT when over-dispatching to seller', function (): void {
    $director = User::factory()->director()->create();
    $seller   = User::factory()->seller()->create();
    $product  = Product::create([
        'name' => 'Dermal-' . uniqid(), 'units_per_box' => 5,
        'base_price_amount' => '750.0000', 'base_price_currency' => 'USD', 'is_active' => true,
    ]);

    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");
    importBoxes($director, $product->id, 1);

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/stock/central/dispatches', [
            'product_id'       => $product->id,
            'destination_type' => 'seller',
            'destination_id'   => $seller->id,
            'quantity_boxes'   => 3,
        ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'STOCK_INSUFFICIENT');
});

// ---------------------------------------------------------------------------
// Exact-match dispatch succeeds (boundary condition)
// ---------------------------------------------------------------------------

it('Dispatching exactly the available amount succeeds', function (): void {
    $director    = User::factory()->director()->create();
    $distributor = User::factory()->distributor()->create();
    $product     = Product::create([
        'name' => 'Pink-Exact-' . uniqid(), 'units_per_box' => 5,
        'base_price_amount' => '750.0000', 'base_price_currency' => 'USD', 'is_active' => true,
    ]);

    $this->actingAs($director, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");

    importBoxes($director, $product->id, 4);

    $action = app(DispatchToDistributorAction::class);

    $result = $action->execute(
        productId: $product->id,
        distributorId: $distributor->id,
        quantityBoxes: 4,
    );

    expect($result['centralStock']->available)->toBe(0);
    expect($result['distributorStock']->available)->toBe(4);
});
