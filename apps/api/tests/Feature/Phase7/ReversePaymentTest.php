<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAccountBalance;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;

/**
 * Phase 7 — ReversePaymentTest
 *
 * §7.6 devolución de cobros rules:
 *   - Only Director can reverse
 *   - Requires non-empty reason
 *   - Updates reversed flag + reversed_by + reversed_at + reversal_reason
 *   - Decrements account balance
 *   - Cannot reverse an already-reversed payment
 *   - Seller attempting reversal → 403
 */

beforeEach(function (): void {
    $this->zone      = Zone::factory()->create(['distributor_id' => null]);
    $this->director  = User::factory()->create(['role' => UserRole::Director]);
    $this->seller    = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer  = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->terms     = PaymentTerm::factory()->create(['days_to_due' => 0]);
    $this->sale      = Sale::factory()->create([
        'status'           => SaleStatus::Confirmed,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'currency'         => 'ARS',
        'total_amount'     => '50000.0000',
        'total_currency'   => 'ARS',
    ]);

    $method = PaymentMethod::where('code', 'cash')->first();
    $this->payment = Payment::create([
        'sale_id'           => $this->sale->id,
        'customer_id'       => $this->customer->id,
        'payment_method_id' => $method->id,
        'amount_amount'     => '50000.0000',
        'amount_currency'   => 'ARS',
        'payment_date'      => '2026-05-08',
        'cash_destination'  => 'dermacells',
        'reversed'          => false,
        'is_advance'        => false,
        'recorded_by'       => $this->seller->id,
    ]);

    // Ensure balance row exists with correct values (observer may have already created it)
    CustomerAccountBalance::updateOrCreate(
        ['customer_id' => $this->customer->id],
        ['balance_ars' => '50000.0000', 'balance_usd' => '0.0000', 'updated_at' => now()],
    );
});

it('Director can reverse a payment with a valid reason', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->payment->id}", [
            'reason' => 'Client requested full reversal due to bank error.',
        ]);

    $response->assertStatus(204);

    $this->payment->refresh();
    expect($this->payment->reversed)->toBeTrue();
    expect($this->payment->reversed_by)->toBe($this->director->id);
    expect($this->payment->reversed_at)->not->toBeNull();
    expect($this->payment->reversal_reason)->toBe('Client requested full reversal due to bank error.');
});

it('account balance decremented after reversal', function (): void {
    $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->payment->id}", [
            'reason' => 'Reversal test — balance check.',
        ])
        ->assertStatus(204);

    $balance = CustomerAccountBalance::where('customer_id', $this->customer->id)->firstOrFail();
    expect((float) $balance->balance_ars)->toBe(0.0);
});

it('Seller cannot reverse a payment (403)', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->payment->id}", [
            'reason' => 'Seller attempting unauthorized reversal.',
        ]);

    $response->assertStatus(403);
});

it('Distributor cannot reverse a payment (403)', function (): void {
    $distributor = User::factory()->create(['role' => UserRole::Distributor]);

    $response = $this->actingAs($distributor, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->payment->id}", [
            'reason' => 'Distributor attempting unauthorized reversal.',
        ]);

    $response->assertStatus(403);
});

it('cannot reverse an already-reversed payment', function (): void {
    // Reverse it once
    $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->payment->id}", ['reason' => 'First reversal.'])
        ->assertStatus(204);

    // Attempt to reverse again
    $response = $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->payment->id}", ['reason' => 'Second reversal attempt.']);

    // Should be 409 or 422 — already reversed
    $response->assertStatus(422);
});

it('reversal requires a non-empty reason of at least 5 chars', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/payments/{$this->payment->id}", [
            'reason' => 'N/A',  // too short
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['reason']);
});
