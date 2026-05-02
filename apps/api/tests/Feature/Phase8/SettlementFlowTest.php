<?php

declare(strict_types=1);

use App\Domain\DistributorFinance\Services\DistributorAccountService;
use App\Enums\PreferredCostModality;
use App\Enums\SaleStatus;
use App\Enums\SettlementStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DistributorAccount;
use App\Models\DistributorPreferredCost;
use App\Models\DistributorSettlement;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\Queue;

/**
 * SettlementFlowTest — Phase 8 §9.4
 *
 * Verifies the full rendición lifecycle:
 *   1. Distributor submits → status = pending
 *   2. Director confirms → status = confirmed → RecalculateDistributorAccountJob dispatched
 *   3. Balance reduces after recalculation
 *   4. Director rejects → status = rejected → balance unchanged
 *   5. Seller cannot submit settlements (403)
 */
beforeEach(function (): void {
    $this->director    = User::factory()->create(['role' => UserRole::Director]);
    $this->distributor = User::factory()->create(['role' => UserRole::Distributor]);
    $this->seller      = User::factory()->create(['role' => UserRole::Seller]);
    $this->zone        = Zone::factory()->create(['distributor_id' => $this->distributor->id]);
    $this->product     = Product::factory()->create([
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
    ]);
    $this->customer    = Customer::factory()->create([
        'zone_id'            => $this->zone->id,
        'assigned_seller_id' => $this->seller->id,
    ]);
    $this->paymentTerms = PaymentTerm::factory()->create(['days_to_due' => 0]);

    DistributorPreferredCost::create([
        'distributor_id' => $this->distributor->id,
        'product_id'     => $this->product->id,
        'modality'       => PreferredCostModality::FixedPrice,
        'value'          => '500.0000',
        'currency'       => 'USD',
        'updated_by'     => $this->director->id,
        'updated_at'     => now(),
    ]);

    // Seed a delivered sale so account has a balance
    $sale = Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => today()->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '1500.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    SaleItem::create([
        'sale_id'             => $sale->id,
        'product_id'          => $this->product->id,
        'quantity_boxes'      => 2,
        'quantity_units'      => 0,
        'unit_price_amount'   => '750.0000',
        'unit_price_currency' => 'USD',
        'subtotal_amount'     => '1500.0000',
    ]);

    // Initialise account
    app(DistributorAccountService::class)->recalculate($this->distributor);
});

it('distributor submits settlement and status is pending', function (): void {
    $response = $this->actingAs($this->distributor, 'sanctum')
        ->postJson('/api/v1/distributors/me/settlements', [
            'amount'   => 500,
            'currency' => 'USD',
            'reference' => 'TRF-2026-001',
        ]);

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe(SettlementStatus::Pending->value);

    $this->assertDatabaseHas('distributor_settlements', [
        'distributor_id' => $this->distributor->id,
        'amount_amount'  => '500.0000',
        'status'         => 'pending',
    ]);
});

it('director confirms settlement and balance reduces after recalculation', function (): void {
    Queue::fake();

    $settlement = DistributorSettlement::create([
        'distributor_id' => $this->distributor->id,
        'amount_amount'  => '500.0000',
        'amount_currency' => 'USD',
        'status'         => SettlementStatus::Pending,
        'submitted_at'   => now(),
    ]);

    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/distributors/settlements/{$settlement->id}/confirm", [
            'action' => 'confirm',
        ]);

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe(SettlementStatus::Confirmed->value);

    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::Confirmed);
    expect($settlement->confirmed_by)->toBe($this->director->id);
    expect($settlement->confirmed_at)->not->toBeNull();

    // RecalculateDistributorAccountJob should be dispatched
    Queue::assertPushed(\App\Jobs\DistributorFinance\RecalculateDistributorAccountJob::class);

    // Now actually run the recalculation to verify balance reduces
    Queue::assertPushed(\App\Jobs\DistributorFinance\RecalculateDistributorAccountJob::class, function ($job) {
        return $job->distributorId === $this->distributor->id;
    });

    // Manually recalculate (since Queue::fake())
    app(DistributorAccountService::class)->recalculate($this->distributor);

    $account = DistributorAccount::where('distributor_id', $this->distributor->id)->first();
    // Balance was USD 1500 (from delivered sale), settlement confirmed = USD 500
    // New balance = 1500 - 500 = 1000
    expect((float) $account->balance_usd)->toBe(1000.0);
});

it('director rejects settlement and balance is unchanged', function (): void {
    $settlement = DistributorSettlement::create([
        'distributor_id'  => $this->distributor->id,
        'amount_amount'   => '500.0000',
        'amount_currency' => 'USD',
        'status'          => SettlementStatus::Pending,
        'submitted_at'    => now(),
    ]);

    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/distributors/settlements/{$settlement->id}/confirm", [
            'action' => 'reject',
            'notes'  => 'Monto incorrecto',
        ]);

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe(SettlementStatus::Rejected->value);

    // Balance remains unchanged (no confirmed settlement)
    app(DistributorAccountService::class)->recalculate($this->distributor);
    $account = DistributorAccount::where('distributor_id', $this->distributor->id)->first();
    expect((float) $account->balance_usd)->toBe(1500.0);
});

it('seller cannot submit settlements (403)', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/distributors/me/settlements', [
            'amount'   => 100,
            'currency' => 'USD',
        ]);

    $response->assertStatus(403);
});

it('cannot confirm an already-confirmed settlement', function (): void {
    $settlement = DistributorSettlement::create([
        'distributor_id'  => $this->distributor->id,
        'amount_amount'   => '200.0000',
        'amount_currency' => 'USD',
        'status'          => SettlementStatus::Confirmed,
        'submitted_at'    => now(),
        'confirmed_by'    => $this->director->id,
        'confirmed_at'    => now(),
    ]);

    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/distributors/settlements/{$settlement->id}/confirm", [
            'action' => 'confirm',
        ]);

    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('SETTLEMENT_NOT_PENDING');
});
