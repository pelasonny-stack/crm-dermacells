<?php

declare(strict_types=1);

use App\Enums\PreferredCostModality;
use App\Enums\SettlementStatus;
use App\Enums\UserRole;
use App\Models\DistributorCommissionPayment;
use App\Models\DistributorPreferredCost;
use App\Models\DistributorSettlement;
use App\Models\User;
use App\Models\Zone;

/**
 * RlsDistribFinanceTest — Phase 8
 *
 * Cross-role denial tests for the distributor finance endpoints.
 *
 * POLICY MATRIX ENFORCED:
 *   distributor_preferred_cost:
 *     - Director sees all (GET /admin/distributors/{id}/preferred-costs)
 *     - Distributor sees own (GET /distributors/me/preferred-costs)
 *     - Seller gets 403 on all distributor finance endpoints
 *
 *   distributor_settlements:
 *     - Distributor B cannot list Distributor A's settlements
 *     - Seller cannot submit settlements
 *     - Only Director can confirm settlements
 *
 *   distributor_commission_payments:
 *     - Seller can see their own commissions (GET /distributors/me/commission-payments
 *       would be forbidden, but the Seller's view is via Phase 9 endpoints)
 *     - Distributor B cannot mark Distributor A's commission payment as paid
 *
 * NOTE: full Postgres RLS is verified via DB-level assertions. API layer
 * checks enforce fast 403 before DB round-trip.
 */
beforeEach(function (): void {
    $this->director     = User::factory()->create(['role' => UserRole::Director]);
    $this->distributorA = User::factory()->create(['role' => UserRole::Distributor]);
    $this->distributorB = User::factory()->create(['role' => UserRole::Distributor]);
    $this->seller       = User::factory()->create(['role' => UserRole::Seller]);

    $this->zoneA = Zone::factory()->create(['distributor_id' => $this->distributorA->id]);
    $this->zoneB = Zone::factory()->create(['distributor_id' => $this->distributorB->id]);

    $this->product = \App\Models\Product::factory()->create([
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
    ]);

    // Preferred cost config for Distributor A
    DistributorPreferredCost::create([
        'distributor_id' => $this->distributorA->id,
        'product_id'     => $this->product->id,
        'modality'       => PreferredCostModality::FixedPrice,
        'value'          => '500.0000',
        'currency'       => 'USD',
        'updated_by'     => $this->director->id,
        'updated_at'     => now(),
    ]);
});

// =========================================================================
// Preferred costs
// =========================================================================

it('director can view preferred costs for any distributor', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->getJson("/api/v1/admin/distributors/{$this->distributorA->id}/preferred-costs");

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(1);
});

it('distributor sees only their own preferred costs', function (): void {
    // Distributor A can see own costs
    $response = $this->actingAs($this->distributorA, 'sanctum')
        ->getJson('/api/v1/distributors/me/preferred-costs');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(1);

    // Distributor B sees zero (no config for them)
    $response = $this->actingAs($this->distributorB, 'sanctum')
        ->getJson('/api/v1/distributors/me/preferred-costs');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(0);
});

it('seller cannot access distributor preferred costs endpoint (403)', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->getJson('/api/v1/distributors/me/preferred-costs');

    $response->assertStatus(403);
});

// =========================================================================
// Settlements
// =========================================================================

it('distributor A cannot see distributor B settlements', function (): void {
    // Create a settlement for Distributor B
    DistributorSettlement::create([
        'distributor_id'  => $this->distributorB->id,
        'amount_amount'   => '100.0000',
        'amount_currency' => 'USD',
        'status'          => SettlementStatus::Pending,
        'submitted_at'    => now(),
    ]);

    // Distributor A's own settlements should be empty
    $response = $this->actingAs($this->distributorA, 'sanctum')
        ->getJson('/api/v1/distributors/me/settlements');

    $response->assertStatus(200);
    // Distributor A has no settlements of their own → empty
    expect($response->json('data'))->toHaveCount(0);
});

it('only director can confirm a settlement', function (): void {
    $settlement = DistributorSettlement::create([
        'distributor_id'  => $this->distributorA->id,
        'amount_amount'   => '200.0000',
        'amount_currency' => 'USD',
        'status'          => SettlementStatus::Pending,
        'submitted_at'    => now(),
    ]);

    // Distributor B cannot confirm Distributor A's settlement
    $response = $this->actingAs($this->distributorB, 'sanctum')
        ->patchJson("/api/v1/distributors/settlements/{$settlement->id}/confirm", [
            'action' => 'confirm',
        ]);

    $response->assertStatus(403);

    // Seller cannot confirm
    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/distributors/settlements/{$settlement->id}/confirm", [
            'action' => 'confirm',
        ]);

    $response->assertStatus(403);

    // Director can confirm
    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/distributors/settlements/{$settlement->id}/confirm", [
            'action' => 'confirm',
        ]);

    $response->assertStatus(200);
});

// =========================================================================
// Commission payments
// =========================================================================

it('distributor B cannot record payment for distributor A commission payment', function (): void {
    $payment = DistributorCommissionPayment::create([
        'distributor_id'             => $this->distributorA->id,
        'seller_id'                  => $this->seller->id,
        'zone_id'                    => $this->zoneA->id,
        'period_month'               => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        'base_amount_amount'         => '1000.0000',
        'base_amount_currency'       => 'USD',
        'commission_pct'             => '0.1500',
        'commission_amount_amount'   => '150.0000',
        'commission_amount_currency' => 'USD',
        'paid'                       => false,
    ]);

    $response = $this->actingAs($this->distributorB, 'sanctum')
        ->patchJson("/api/v1/distributors/commission-payments/{$payment->id}/pay");

    $response->assertStatus(403);
});

it('director can set preferred cost for any distributor', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->putJson("/api/v1/admin/distributors/{$this->distributorB->id}/preferred-costs/{$this->product->id}", [
            'modality' => 'discount_pct',
            'value'    => '0.15',
        ]);

    $response->assertStatus(200);
    expect($response->json('data.modality'))->toBe('discount_pct');
    expect($response->json('data.value'))->toBe('0.1500');
});

it('seller cannot set preferred costs via admin endpoint (403)', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->putJson("/api/v1/admin/distributors/{$this->distributorA->id}/preferred-costs/{$this->product->id}", [
            'modality' => 'fixed_price',
            'value'    => '100',
        ]);

    $response->assertStatus(403);
});
