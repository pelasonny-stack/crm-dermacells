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
use Illuminate\Support\Str;

/**
 * Phase 7 — DualCurrencyAccountTest
 *
 * Verifies the core dual-currency account balance invariant:
 *   - ARS payments increment ONLY balance_ars
 *   - USD payments increment ONLY balance_usd
 *   - No conversion between currencies ever occurs in the balance table
 *
 * §7.4: "Los cobros en ARS acumulan en ARS; los cobros en USD acumulan en USD.
 *  La conversión a USD equivalente solo se usa para el cálculo del escalón de
 *  comisiones del Vendedor."
 */

beforeEach(function (): void {
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->terms    = PaymentTerm::factory()->create(['days_to_due' => 0]);
    $this->arsMethod = PaymentMethod::where('code', 'cash')->first();
    $this->usdMethod = PaymentMethod::where('code', 'transfer_dermacells')->first();
});

it('ARS payment increments only balance_ars, not balance_usd', function (): void {
    $sale = Sale::factory()->create([
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'status'           => SaleStatus::Confirmed,
        'currency'         => 'ARS',
        'total_amount'     => '50000.0000',
        'total_currency'   => 'ARS',
    ]);

    $this->actingAs($this->seller, 'sanctum')
        ->withHeaders(['Idempotency-Key' => Str::uuid()->toString()])->postJson('/api/v1/payments', [
            'sale_id'           => $sale->id,
            'payment_method_id' => $this->arsMethod->id,
            'amount'            => '50000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
        ])
        ->assertStatus(201);

    $balance = CustomerAccountBalance::where('customer_id', $this->customer->id)->firstOrFail();

    expect((float) $balance->balance_ars)->toBe(50000.0);
    expect((float) $balance->balance_usd)->toBe(0.0);
});

it('USD payment increments only balance_usd, not balance_ars', function (): void {
    $sale = Sale::factory()->create([
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'status'           => SaleStatus::Confirmed,
        'currency'         => 'USD',
        'total_amount'     => '750.0000',
        'total_currency'   => 'USD',
    ]);

    $this->actingAs($this->seller, 'sanctum')
        ->withHeaders(['Idempotency-Key' => Str::uuid()->toString()])->postJson('/api/v1/payments', [
            'sale_id'           => $sale->id,
            'payment_method_id' => $this->usdMethod->id,
            'amount'            => '750',
            'currency'          => 'USD',
            'payment_date'      => '2026-05-08',
            'reference'         => 'WIRE-USD-001',
        ])
        ->assertStatus(201);

    $balance = CustomerAccountBalance::where('customer_id', $this->customer->id)->firstOrFail();

    expect((float) $balance->balance_usd)->toBe(750.0);
    expect((float) $balance->balance_ars)->toBe(0.0);
});

it('multiple payments in different currencies accumulate independently', function (): void {
    $arsSale = Sale::factory()->create([
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'status'           => SaleStatus::Confirmed,
        'currency'         => 'ARS',
        'total_amount'     => '30000.0000',
        'total_currency'   => 'ARS',
    ]);

    $usdSale = Sale::factory()->create([
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'status'           => SaleStatus::Confirmed,
        'currency'         => 'USD',
        'total_amount'     => '500.0000',
        'total_currency'   => 'USD',
    ]);

    $actor = $this->actingAs($this->seller, 'sanctum');

    $actor->withHeaders(['Idempotency-Key' => Str::uuid()->toString()])->postJson('/api/v1/payments', [
        'sale_id'           => $arsSale->id,
        'payment_method_id' => $this->arsMethod->id,
        'amount'            => '30000',
        'currency'          => 'ARS',
        'payment_date'      => '2026-05-08',
    ])->assertStatus(201);

    $actor->withHeaders(['Idempotency-Key' => Str::uuid()->toString()])->postJson('/api/v1/payments', [
        'sale_id'           => $usdSale->id,
        'payment_method_id' => $this->usdMethod->id,
        'amount'            => '500',
        'currency'          => 'USD',
        'payment_date'      => '2026-05-08',
        'reference'         => 'WIRE-USD-002',
    ])->assertStatus(201);

    $balance = CustomerAccountBalance::where('customer_id', $this->customer->id)->firstOrFail();

    expect((float) $balance->balance_ars)->toBe(30000.0);
    expect((float) $balance->balance_usd)->toBe(500.0);
});

it('GET /customers/{id}/account-balance returns separate ARS and USD balances', function (): void {
    // Seed a balance row directly to avoid dependency on payment registration in this test
    CustomerAccountBalance::updateOrCreate(
        ['customer_id' => $this->customer->id],
        ['balance_ars' => '25000.0000', 'balance_usd' => '300.0000', 'updated_at' => now()],
    );

    $response = $this->actingAs($this->seller, 'sanctum')
        ->getJson("/api/v1/customers/{$this->customer->id}/account-balance");

    $response->assertStatus(200);
    $response->assertJsonPath('customer_id', $this->customer->id);
    $response->assertJsonPath('balance_ars', '25000.0000');
    $response->assertJsonPath('balance_usd', '300.0000');
});
