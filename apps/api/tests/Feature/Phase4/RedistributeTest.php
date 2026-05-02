<?php

declare(strict_types=1);

use App\Actions\Stock\DispatchToDistributorAction;
use App\Actions\Stock\RedistributeFromDistributorAction;
use App\Actions\Stock\RegisterImportAction;
use App\Enums\StockMovementType;
use App\Exceptions\StockInsufficientException;
use App\Models\DistributorStock;
use App\Models\Product;
use App\Models\SellerStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/*
|--------------------------------------------------------------------------
| Phase 4 — Redistribution: Distributor → Seller
|--------------------------------------------------------------------------
|
| Verifies the full redistribution flow:
|   1. Director imports stock (central_stock)
|   2. Director dispatches to Distributor (distributor_stock)
|   3. Distributor redistributes to Seller (seller_stock)
|
| Also verifies authorization and insufficient stock guard.
|
*/

function setupCentralAndDispatch(): array
{
    $director    = User::factory()->director()->create();
    $distributor = User::factory()->distributor()->create();
    $seller      = User::factory()->seller()->create();
    $product     = Product::create([
        'name'                => 'Dermal-Redist-' . uniqid(),
        'units_per_box'       => 5,
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
        'is_active'           => true,
    ]);

    // Director imports 20 boxes
    $importAction = app(RegisterImportAction::class);
    $importAction->execute(
        productId: $product->id,
        quantityBoxes: 20,
        importDate: now()->toDateString(),
        lotNumber: 'LOT-REDIST',
    );

    // Director dispatches 12 boxes to distributor
    $dispatchAction = app(DispatchToDistributorAction::class);
    $dispatchAction->execute(
        productId: $product->id,
        distributorId: $distributor->id,
        quantityBoxes: 12,
    );

    return compact('director', 'distributor', 'seller', 'product');
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('Distributor can redistribute boxes to a seller and seller_stock is created', function (): void {
    ['director' => $director, 'distributor' => $distributor, 'seller' => $seller, 'product' => $product]
        = setupCentralAndDispatch();

    $this->actingAs($distributor, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $distributor->id));
    DB::statement("SET LOCAL app.user_role = 'distributor'");

    $action = app(RedistributeFromDistributorAction::class);

    $result = $action->execute(
        productId: $product->id,
        sellerId: $seller->id,
        quantityBoxes: 5,
        referenceDoc: 'DESPACHO-001',
    );

    // Distributor stock: available decremented, total_redistributed incremented
    expect($result['distributorStock']->available)->toBe(7);
    expect($result['distributorStock']->total_redistributed)->toBe(5);
    expect($result['distributorStock']->total_received)->toBe(12);

    // Seller stock created and populated
    expect($result['sellerStock'])->toBeInstanceOf(SellerStock::class);
    expect($result['sellerStock']->boxes)->toBe(5);
    expect($result['sellerStock']->seller_id)->toBe($seller->id);

    // Movement type is redistribution
    expect($result['movement']->movement_type)->toBe(StockMovementType::Redistribution);
    expect($result['movement']->from_entity_type)->toBe('distributor');
    expect($result['movement']->to_entity_type)->toBe('seller');
    expect($result['movement']->quantity_boxes)->toBe(5);
});

it('Multiple redistributions accumulate in seller_stock', function (): void {
    ['distributor' => $distributor, 'seller' => $seller, 'product' => $product]
        = setupCentralAndDispatch();

    $this->actingAs($distributor, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $distributor->id));
    DB::statement("SET LOCAL app.user_role = 'distributor'");

    $action = app(RedistributeFromDistributorAction::class);

    $action->execute(productId: $product->id, sellerId: $seller->id, quantityBoxes: 3);
    $result = $action->execute(productId: $product->id, sellerId: $seller->id, quantityBoxes: 4);

    expect($result['sellerStock']->boxes)->toBe(7);
    expect($result['distributorStock']->total_redistributed)->toBe(7);
    expect($result['distributorStock']->available)->toBe(5); // 12 - 7
});

// ---------------------------------------------------------------------------
// API endpoint
// ---------------------------------------------------------------------------

it('POST /v1/stock/redistribute returns 201 for Distributor', function (): void {
    ['distributor' => $distributor, 'seller' => $seller, 'product' => $product]
        = setupCentralAndDispatch();

    $response = $this->actingAs($distributor, 'sanctum')
        ->postJson('/api/v1/stock/redistribute', [
            'product_id'     => $product->id,
            'seller_id'      => $seller->id,
            'quantity_boxes' => 3,
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['movement_id']]);
});

it('POST /v1/stock/redistribute returns 403 for Seller', function (): void {
    $seller  = User::factory()->seller()->create();
    $product = Product::factory()->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson('/api/v1/stock/redistribute', [
            'product_id'     => $product->id,
            'seller_id'      => $seller->id,
            'quantity_boxes' => 1,
        ]);

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// Insufficient stock guard
// ---------------------------------------------------------------------------

it('RedistributeFromDistributorAction throws StockInsufficientException when over-redistributing', function (): void {
    ['distributor' => $distributor, 'seller' => $seller, 'product' => $product]
        = setupCentralAndDispatch();

    $this->actingAs($distributor, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $distributor->id));
    DB::statement("SET LOCAL app.user_role = 'distributor'");

    $action = app(RedistributeFromDistributorAction::class);

    expect(fn () => $action->execute(
        productId: $product->id,
        sellerId: $seller->id,
        quantityBoxes: 100,
    ))->toThrow(StockInsufficientException::class);
});

it('POST /v1/stock/redistribute returns 422 STOCK_INSUFFICIENT when over-redistributing', function (): void {
    ['distributor' => $distributor, 'seller' => $seller, 'product' => $product]
        = setupCentralAndDispatch();

    $response = $this->actingAs($distributor, 'sanctum')
        ->postJson('/api/v1/stock/redistribute', [
            'product_id'     => $product->id,
            'seller_id'      => $seller->id,
            'quantity_boxes' => 100,
        ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'STOCK_INSUFFICIENT');
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('Director cannot call RedistributeFromDistributorAction (distributor-only)', function (): void {
    $director = User::factory()->director()->create();
    $seller   = User::factory()->seller()->create();
    $product  = Product::factory()->create();

    $this->actingAs($director, 'sanctum');
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $director->id));
    DB::statement("SET LOCAL app.user_role = 'director'");

    $action = app(RedistributeFromDistributorAction::class);

    expect(fn () => $action->execute(
        productId: $product->id,
        sellerId: $seller->id,
        quantityBoxes: 1,
    ))->toThrow(UnauthorizedException::class);
});
