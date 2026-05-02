<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;

/**
 * Phase 7 — RlsPaymentVisibilityTest
 *
 * Verifies cross-role visibility for the payments table per §2.4:
 *
 *   Director     → sees ALL payments
 *   Distributor  → sees payments on sales in their managed zones ONLY
 *   Seller       → sees ONLY their own sales' payments
 *
 * RLS policies are defined in 2026_05_08_000005_enable_payments_rls.
 */

beforeEach(function (): void {
    $this->director     = User::factory()->create(['role' => UserRole::Director]);
    $this->distributor  = User::factory()->create(['role' => UserRole::Distributor]);
    $this->sellerA      = User::factory()->create(['role' => UserRole::Seller]);
    $this->sellerB      = User::factory()->create(['role' => UserRole::Seller]);

    // Zone A — managed by $distributor
    $this->zoneA = Zone::factory()->create(['distributor_id' => $this->distributor->id]);
    // Zone B — zona directa
    $this->zoneB = Zone::factory()->create(['distributor_id' => null]);

    $this->customerA = Customer::factory()->create([
        'zone_id'            => $this->zoneA->id,
        'assigned_seller_id' => $this->sellerA->id,
    ]);
    $this->customerB = Customer::factory()->create([
        'zone_id'            => $this->zoneB->id,
        'assigned_seller_id' => $this->sellerB->id,
    ]);

    $terms = PaymentTerm::factory()->create(['days_to_due' => 0]);

    $this->saleA = Sale::factory()->create([
        'seller_id'        => $this->sellerA->id,
        'customer_id'      => $this->customerA->id,
        'zone_id'          => $this->zoneA->id,
        'payment_terms_id' => $terms->id,
        'status'           => SaleStatus::Confirmed,
        'currency'         => 'ARS',
        'total_amount'     => '50000.0000',
        'total_currency'   => 'ARS',
    ]);

    $this->saleB = Sale::factory()->create([
        'seller_id'        => $this->sellerB->id,
        'customer_id'      => $this->customerB->id,
        'zone_id'          => $this->zoneB->id,
        'payment_terms_id' => $terms->id,
        'status'           => SaleStatus::Confirmed,
        'currency'         => 'ARS',
        'total_amount'     => '30000.0000',
        'total_currency'   => 'ARS',
    ]);

    $cashMethod = PaymentMethod::where('code', 'cash')->first();

    $this->paymentA = Payment::create([
        'sale_id'           => $this->saleA->id,
        'customer_id'       => $this->customerA->id,
        'payment_method_id' => $cashMethod->id,
        'amount_amount'     => '50000.0000',
        'amount_currency'   => 'ARS',
        'payment_date'      => '2026-05-08',
        'cash_destination'  => 'distributor',
        'cash_destination_dist_id' => $this->distributor->id,
        'reversed'          => false,
        'is_advance'        => false,
        'recorded_by'       => $this->sellerA->id,
    ]);

    $this->paymentB = Payment::create([
        'sale_id'           => $this->saleB->id,
        'customer_id'       => $this->customerB->id,
        'payment_method_id' => $cashMethod->id,
        'amount_amount'     => '30000.0000',
        'amount_currency'   => 'ARS',
        'payment_date'      => '2026-05-08',
        'cash_destination'  => 'dermacells',
        'reversed'          => false,
        'is_advance'        => false,
        'recorded_by'       => $this->sellerB->id,
    ]);
});

it('Director sees all payments from all zones', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->getJson('/api/v1/payments');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->paymentA->id);
    expect($ids)->toContain($this->paymentB->id);
});

it('Seller A sees only their own payments, not Seller B payments', function (): void {
    $response = $this->actingAs($this->sellerA, 'sanctum')
        ->getJson('/api/v1/payments');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->paymentA->id);
    expect($ids)->not->toContain($this->paymentB->id);
});

it('Seller B sees only their own payments, not Seller A payments', function (): void {
    $response = $this->actingAs($this->sellerB, 'sanctum')
        ->getJson('/api/v1/payments');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->paymentB->id);
    expect($ids)->not->toContain($this->paymentA->id);
});

it('Distributor sees payments in their zone (Zone A) but not Zone B (zona directa)', function (): void {
    $response = $this->actingAs($this->distributor, 'sanctum')
        ->getJson('/api/v1/payments');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->paymentA->id);
    expect($ids)->not->toContain($this->paymentB->id);
});

it('Seller cannot reverse a payment even with valid reason (403)', function (): void {
    $response = $this->actingAs($this->sellerA, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->paymentA->id}", [
            'reason' => 'Attempting reversal as seller.',
        ]);

    $response->assertStatus(403);
});
