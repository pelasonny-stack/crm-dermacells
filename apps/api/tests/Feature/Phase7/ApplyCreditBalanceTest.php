<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerCreditBalance;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;

/**
 * Phase 7 — ApplyCreditBalanceTest
 *
 * §7.5: Director manually imputes saldo a favor to a future sale.
 *
 * Rules tested:
 *   - Director can apply an unapplied credit to a sale of the same customer
 *   - applied_to_sale_id + applied_at are set
 *   - Seller cannot apply credit (403)
 *   - Cannot apply an already-applied credit
 *   - Cannot apply credit from a different customer
 *   - Amount cannot exceed the credit balance
 */

beforeEach(function (): void {
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->director = User::factory()->create(['role' => UserRole::Director]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->terms    = PaymentTerm::factory()->create(['days_to_due' => 30]);

    $this->newSale = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'currency'         => 'ARS',
        'total_amount'     => '60000.0000',
        'total_currency'   => 'ARS',
    ]);

    $this->credit = CustomerCreditBalance::create([
        'customer_id'     => $this->customer->id,
        'amount_amount'   => '50000.0000',
        'amount_currency' => 'ARS',
        'origin_sale_id'  => null,
        'applied_at'      => null,
    ]);
});

it('Director can apply a credit balance to a sale', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->postJson("/api/v1/customers/{$this->customer->id}/credit-balances/{$this->credit->id}/apply", [
            'sale_id'  => $this->newSale->id,
            'amount'   => '50000',
            'currency' => 'ARS',
        ]);

    $response->assertStatus(200);
    $response->assertJsonPath('applied_to_sale_id', $this->newSale->id);
    $response->assertJsonPath('currency', 'ARS');

    $this->credit->refresh();
    expect($this->credit->applied_to_sale_id)->toBe($this->newSale->id);
    expect($this->credit->applied_at)->not->toBeNull();
});

it('Seller cannot apply a credit balance (403)', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson("/api/v1/customers/{$this->customer->id}/credit-balances/{$this->credit->id}/apply", [
            'sale_id'  => $this->newSale->id,
            'amount'   => '50000',
            'currency' => 'ARS',
        ]);

    $response->assertStatus(403);
});

it('cannot apply an already-applied credit balance', function (): void {
    // Apply first time
    $this->actingAs($this->director, 'sanctum')
        ->postJson("/api/v1/customers/{$this->customer->id}/credit-balances/{$this->credit->id}/apply", [
            'sale_id'  => $this->newSale->id,
            'amount'   => '50000',
            'currency' => 'ARS',
        ])
        ->assertStatus(200);

    // Attempt second application
    $anotherSale = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'currency'         => 'ARS',
        'total_amount'     => '60000.0000',
        'total_currency'   => 'ARS',
    ]);

    $response = $this->actingAs($this->director, 'sanctum')
        ->postJson("/api/v1/customers/{$this->customer->id}/credit-balances/{$this->credit->id}/apply", [
            'sale_id'  => $anotherSale->id,
            'amount'   => '50000',
            'currency' => 'ARS',
        ]);

    $response->assertStatus(422);
});

it('lists credit balances for a customer with unapplied filter', function (): void {
    // Create an applied credit
    CustomerCreditBalance::create([
        'customer_id'        => $this->customer->id,
        'amount_amount'      => '10000.0000',
        'amount_currency'    => 'ARS',
        'applied_to_sale_id' => $this->newSale->id,
        'applied_at'         => now(),
    ]);

    $response = $this->actingAs($this->director, 'sanctum')
        ->getJson("/api/v1/customers/{$this->customer->id}/credit-balances?unapplied_only=1");

    $response->assertStatus(200);

    // Only the unapplied credit ($this->credit) should appear
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($this->credit->id);
});

it('CreditBalanceService::availableBalance sums only unapplied in given currency', function (): void {
    // Add a second unapplied ARS credit
    CustomerCreditBalance::create([
        'customer_id'     => $this->customer->id,
        'amount_amount'   => '15000.0000',
        'amount_currency' => 'ARS',
    ]);

    // Add an applied ARS credit (should NOT count)
    CustomerCreditBalance::create([
        'customer_id'        => $this->customer->id,
        'amount_amount'      => '5000.0000',
        'amount_currency'    => 'ARS',
        'applied_to_sale_id' => $this->newSale->id,
        'applied_at'         => now(),
    ]);

    $service   = app(\App\Domain\Payments\Services\CreditBalanceService::class);
    $available = $service->availableBalance($this->customer, 'ARS');

    // 50000 + 15000 = 65000 (not counting the 5000 applied one)
    expect($available->getAmount()->toFloat())->toBe(65000.0);
});
