<?php

declare(strict_types=1);

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Stock\ReserveStockOnSaleConfirmAction;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Exceptions\StockInsufficientException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->director  = User::factory()->create(['role' => UserRole::Director]);
    $this->zone      = \App\Models\Zone::factory()->create(['distributor_id' => null]);
    $this->seller    = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer  = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->product       = Product::factory()->create();
    $this->paymentTerms  = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);

    $this->sale = Sale::factory()->create([
        'status'          => SaleStatus::Draft,
        'seller_id'       => $this->seller->id,
        'customer_id'     => $this->customer->id,
        'zone_id'         => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'        => 'USD',
        'total_amount'    => '1500.0000',
        'total_currency'  => 'USD',
    ]);

    SaleItem::factory()->create([
        'sale_id'             => $this->sale->id,
        'product_id'          => $this->product->id,
        'quantity_boxes'      => 2,
        'quantity_units'      => 0,
        'unit_price_amount'   => '750.0000',
        'unit_price_currency' => 'USD',
        'subtotal_amount'     => '1500.0000',
    ]);
});

it('returns 422 with STOCK_INSUFFICIENT when reserve stock action throws', function (): void {
    // Mock the Phase 4 reserve action to simulate insufficient stock
    $this->mock(ReserveStockOnSaleConfirmAction::class, function ($mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andThrow(new StockInsufficientException(
                productId: $this->product->id,
                requested: 10,
                available: 2,
            ));
    });

    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/confirm");

    $response->assertStatus(422);
    $body = $response->json();
    expect($body['code'])->toBe('STOCK_INSUFFICIENT');

    // Sale must remain in draft
    $this->sale->refresh();
    expect($this->sale->status)->toBe(SaleStatus::Draft);
});

it('leaves the sale in draft when stock reservation fails', function (): void {
    $this->mock(ReserveStockOnSaleConfirmAction::class, function ($mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andThrow(new StockInsufficientException($this->product->id, 10, 0));
    });

    $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/confirm");

    expect($this->sale->fresh()->status)->toBe(SaleStatus::Draft);
});
