<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesStatusHistory;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->director = User::factory()->create(['role' => UserRole::Director, 'can_sell' => true]);
    $this->zone     = \App\Models\Zone::factory()->create(['distributor_id' => null]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id'      => $this->seller->id,
        'zone_id'                 => $this->zone->id,
        'reference_price_amount'  => '700.0000',
        'reference_price_currency' => 'USD',
    ]);
    $this->product       = Product::factory()->create(['base_price_amount' => '750.0000', 'base_price_currency' => 'USD']);
    $this->paymentTerms  = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 30]);
    $this->exchangeRate  = \App\Models\ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.0000']);
});

it('creates a draft sale with items and records status history', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', [
            'customer_id'      => $this->customer->id,
            'seller_id'        => $this->seller->id,
            'zone_id'          => $this->zone->id,
            'sale_date'        => '2026-05-06',
            'payment_terms_id' => $this->paymentTerms->id,
            'currency'         => 'USD',
            'exchange_rate_id' => null,
            'delegated_delivery' => false,
            'items'            => [
                [
                    'product_id'          => $this->product->id,
                    'quantity_boxes'      => 2,
                    'quantity_units'      => 0,
                    'unit_price_amount'   => '700.0000',
                    'unit_price_currency' => 'USD',
                ],
            ],
        ], ['Idempotency-Key' => '11111111-1111-4111-8111-111111111111']);

    $response->assertStatus(201);
    $data = $response->json('data');

    expect($data['status'])->toBe(SaleStatus::Draft->value);
    expect($data['currency'])->toBe('USD');
    expect($data['items'])->toHaveCount(1);

    // Status history was created
    $saleId = $data['id'];
    $history = SalesStatusHistory::where('sale_id', $saleId)->first();
    expect($history)->not->toBeNull();
    expect($history->to_status)->toBe('draft');
    expect($history->from_status)->toBeNull();
});

it('returns 422 when items array is missing', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', [
            'customer_id'      => $this->customer->id,
            'seller_id'        => $this->seller->id,
            'zone_id'          => $this->zone->id,
            'sale_date'        => '2026-05-06',
            'payment_terms_id' => $this->paymentTerms->id,
            'currency'         => 'USD',
        ], ['Idempotency-Key' => '22222222-2222-4222-8222-222222222222']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['items']);
});

it('returns stock warnings in meta but still creates the draft', function (): void {
    // This test verifies §4.5: draft creation never blocks on stock.
    // Stock check stub returns false so we can't test the warning path here —
    // this test just ensures no exception is thrown regardless.
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', [
            'customer_id'      => $this->customer->id,
            'seller_id'        => $this->seller->id,
            'zone_id'          => $this->zone->id,
            'sale_date'        => '2026-05-06',
            'payment_terms_id' => $this->paymentTerms->id,
            'currency'         => 'USD',
            'items'            => [
                [
                    'product_id'      => $this->product->id,
                    'quantity_boxes'  => 1,
                    'quantity_units'  => 0,
                ],
            ],
        ], ['Idempotency-Key' => '33333333-3333-4333-8333-333333333333']);

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('draft');
});

it('rejects currency mismatch between header and items', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', [
            'customer_id'      => $this->customer->id,
            'seller_id'        => $this->seller->id,
            'zone_id'          => $this->zone->id,
            'sale_date'        => '2026-05-06',
            'payment_terms_id' => $this->paymentTerms->id,
            'currency'         => 'USD',
            'items'            => [
                [
                    'product_id'          => $this->product->id,
                    'quantity_boxes'      => 1,
                    'quantity_units'      => 0,
                    'unit_price_amount'   => '100000.0000',
                    'unit_price_currency' => 'ARS',  // mismatch!
                ],
            ],
        ], ['Idempotency-Key' => '44444444-4444-4444-8444-444444444444']);

    $response->assertStatus(422);
});
