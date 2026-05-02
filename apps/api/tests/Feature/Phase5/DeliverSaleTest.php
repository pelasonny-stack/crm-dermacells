<?php

declare(strict_types=1);

use App\Actions\Stock\CommitStockOnDeliverAction;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesStatusHistory;
use App\Models\User;

beforeEach(function (): void {
    $this->director     = User::factory()->create(['role' => UserRole::Director]);
    $this->distributor  = User::factory()->create(['role' => UserRole::Distributor]);
    $this->zone         = \App\Models\Zone::factory()->create(['distributor_id' => $this->distributor->id]);
    $this->seller       = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer     = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->product      = Product::factory()->create();
    $this->paymentTerms = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);

    // Confirmed non-delegated sale
    $this->sale = Sale::factory()->create([
        'status'             => SaleStatus::Confirmed,
        'seller_id'          => $this->seller->id,
        'customer_id'        => $this->customer->id,
        'zone_id'            => $this->zone->id,
        'payment_terms_id'   => $this->paymentTerms->id,
        'currency'           => 'USD',
        'total_amount'       => '750.0000',
        'total_currency'     => 'USD',
        'delegated_delivery' => false,
    ]);
});

it('seller can deliver their own non-delegated confirmed sale', function (): void {
    // Mock commit stock (no-op in Phase 5)
    $this->mock(CommitStockOnDeliverAction::class, function ($mock): void {
        $mock->shouldReceive('execute')->zeroOrMoreTimes();
    });

    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/deliver");

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', SaleStatus::Delivered->value);

    $history = SalesStatusHistory::where('sale_id', $this->sale->id)
        ->where('to_status', 'delivered')
        ->first();
    expect($history)->not->toBeNull();
    expect($history->from_status)->toBe('confirmed');
});

it('director can deliver any sale', function (): void {
    $this->mock(CommitStockOnDeliverAction::class, fn ($m) => $m->shouldReceive('execute')->zeroOrMoreTimes());

    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/deliver");

    $response->assertStatus(200);
});

it('cannot deliver a draft sale (invalid transition)', function (): void {
    $draftSale = Sale::factory()->create([
        'status'          => SaleStatus::Draft,
        'seller_id'       => $this->seller->id,
        'customer_id'     => $this->customer->id,
        'zone_id'         => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'        => 'USD',
        'total_amount'    => '0.0000',
        'total_currency'  => 'USD',
    ]);

    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$draftSale->id}/deliver");

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('INVALID_TRANSITION');
});
