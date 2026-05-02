<?php

declare(strict_types=1);

use App\Actions\Stock\RegisterImportAction;
use App\Enums\UserRole;
use App\Models\CentralStock;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/*
|--------------------------------------------------------------------------
| Phase 4 — RegisterImportAction tests
|--------------------------------------------------------------------------
|
| Happy path: Director registers an import, expect StockLot + StockMovement
| created, and central_stock.total_imported incremented.
|
| Authorization: non-Director users (Distributor, Seller) must be rejected.
|
*/

function makeProduct(): Product
{
    return Product::create([
        'name'                  => 'Dermal-' . uniqid(),
        'units_per_box'         => 5,
        'base_price_amount'     => '750.0000',
        'base_price_currency'   => 'USD',
        'is_active'             => true,
    ]);
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('Director can register an import and central stock is updated', function (): void {
    $director = User::factory()->director()->create();
    $product  = makeProduct();

    $this->actingAs($director, 'sanctum');

    // Set GUCs for the action (action reads Auth::user() which is set by actingAs)
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");

    $action = app(RegisterImportAction::class);

    $result = $action->execute(
        productId: $product->id,
        quantityBoxes: 10,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-001',
        supplier: 'Proveedor SA',
        expiryDate: now()->addYear()->toDateString(),
        referenceDoc: 'REM-001',
    );

    expect($result['lot'])->toBeInstanceOf(StockLot::class);
    expect($result['movement'])->toBeInstanceOf(StockMovement::class);
    expect($result['centralStock'])->toBeInstanceOf(CentralStock::class);

    // Lot was created correctly
    expect($result['lot']->product_id)->toBe($product->id);
    expect($result['lot']->lot_number)->toBe('LOT-001');
    expect($result['lot']->quantity_boxes)->toBe(10);
    expect($result['lot']->supplier)->toBe('Proveedor SA');

    // Movement was created with correct type
    expect($result['movement']->movement_type->value)->toBe('import');
    expect($result['movement']->quantity_boxes)->toBe(10);
    expect($result['movement']->product_id)->toBe($product->id);

    // Central stock reflects the import
    expect($result['centralStock']->total_imported)->toBe(10);
    expect($result['centralStock']->total_dispatched)->toBe(0);
    expect($result['centralStock']->available)->toBe(10);
});

it('Second import for same product accumulates total_imported', function (): void {
    $director = User::factory()->director()->create();
    $product  = makeProduct();

    $this->actingAs($director, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");

    $action = app(RegisterImportAction::class);

    $action->execute(
        productId: $product->id,
        quantityBoxes: 5,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-A',
    );

    $result2 = $action->execute(
        productId: $product->id,
        quantityBoxes: 8,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-B',
    );

    expect($result2['centralStock']->total_imported)->toBe(13);
    expect($result2['centralStock']->available)->toBe(13);
    expect(StockLot::where('product_id', $product->id)->count())->toBe(2);
});

it('Import without expiry_date still succeeds', function (): void {
    $director = User::factory()->director()->create();
    $product  = makeProduct();

    $this->actingAs($director, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");

    $action = app(RegisterImportAction::class);

    $result = $action->execute(
        productId: $product->id,
        quantityBoxes: 3,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-NO-EXPIRY',
    );

    expect($result['lot']->expiry_date)->toBeNull();
    expect($result['centralStock']->available)->toBe(3);
});

// ---------------------------------------------------------------------------
// Authorization enforcement
// ---------------------------------------------------------------------------

it('Distributor cannot register an import', function (): void {
    $distributor = User::factory()->distributor()->create();
    $product     = makeProduct();

    $this->actingAs($distributor, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $distributor->id));
    DB::statement("SET LOCAL app.user_role = 'distributor'");

    $action = app(RegisterImportAction::class);

    expect(fn () => $action->execute(
        productId: $product->id,
        quantityBoxes: 5,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-DENY',
    ))->toThrow(UnauthorizedException::class);
});

it('Seller cannot register an import', function (): void {
    $seller  = User::factory()->seller()->create();
    $product = makeProduct();

    $this->actingAs($seller, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $seller->id));
    DB::statement("SET LOCAL app.user_role = 'seller'");

    $action = app(RegisterImportAction::class);

    expect(fn () => $action->execute(
        productId: $product->id,
        quantityBoxes: 5,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-DENY',
    ))->toThrow(UnauthorizedException::class);
});

// ---------------------------------------------------------------------------
// API endpoint
// ---------------------------------------------------------------------------

it('POST /v1/stock/central/imports returns 201 for Director', function (): void {
    $director = User::factory()->director()->create();
    $product  = makeProduct();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/stock/central/imports', [
            'product_id'     => $product->id,
            'quantity_boxes' => 7,
            'import_date'    => now()->toDateString(),
            'lot_number'     => 'LOT-API-001',
            'supplier'       => 'Suplidora ARG',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.available', 7)
        ->assertJsonPath('data.total_imported', 7);
});

it('POST /v1/stock/central/imports returns 403 for Seller', function (): void {
    $seller  = User::factory()->seller()->create();
    $product = makeProduct();

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson('/api/v1/stock/central/imports', [
            'product_id'     => $product->id,
            'quantity_boxes' => 7,
            'import_date'    => now()->toDateString(),
            'lot_number'     => 'LOT-DENY',
        ]);

    $response->assertForbidden();
});

it('POST /v1/stock/central/imports returns 422 for missing required fields', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/stock/central/imports', []);

    $response->assertUnprocessable();
});
