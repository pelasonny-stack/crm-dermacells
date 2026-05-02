<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAccountBalance;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use App\Models\PaymentTerm;

/**
 * Phase 7 — RegisterPaymentTest
 *
 * Covers happy-path registration for each payment medium, and verifies
 * that account balance is updated correctly on each creation.
 */

beforeEach(function (): void {
    $this->zone         = Zone::factory()->create(['distributor_id' => null]);
    $this->director     = User::factory()->create(['role' => UserRole::Director]);
    $this->seller       = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer     = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = PaymentTerm::factory()->create(['days_to_due' => 30]);
    $this->sale         = Sale::factory()->create([
        'status'           => SaleStatus::Confirmed,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'ARS',
        'total_amount'     => '100000.0000',
        'total_currency'   => 'ARS',
    ]);
});

it('seller can register a transfer payment (transfer_dermacells)', function (): void {
    $method = PaymentMethod::where('code', 'transfer_dermacells')->first();

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '50000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
            'reference'         => 'CBU-123456',
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('currency', 'ARS');
    $response->assertJsonPath('payment_method', 'Transferencia a cuenta Dermacells');

    expect(Payment::where('sale_id', $this->sale->id)->exists())->toBeTrue();
});

it('seller can register a credit card payment with installments', function (): void {
    $method = PaymentMethod::where('code', 'credit_card')->first();

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '75000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
            'installments'      => 6,
        ]);

    $response->assertStatus(201);

    $payment = Payment::where('sale_id', $this->sale->id)->first();
    expect($payment->installments)->toBe(6);
});

it('seller can register a cheque payment with all required check fields', function (): void {
    $method = PaymentMethod::where('code', 'check')->first();

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '30000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
            'check_number'      => 'CH-0012345',
            'check_bank'        => 'Banco Galicia',
            'check_due_date'    => '2026-06-08',
        ]);

    $response->assertStatus(201);

    $payment = Payment::where('sale_id', $this->sale->id)->first();
    expect($payment->check_number)->toBe('CH-0012345');
    expect($payment->check_bank)->toBe('Banco Galicia');
});

it('cash destination is auto-resolved to dermacells for zona directa', function (): void {
    // Zone has no distributor (zona directa)
    $method = PaymentMethod::where('code', 'cash')->first();

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '20000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('cash_destination', 'dermacells');

    $payment = Payment::where('sale_id', $this->sale->id)->first();
    expect($payment->cash_destination)->toBe('dermacells');
    expect($payment->cash_destination_dist_id)->toBeNull();
});

it('cash destination is auto-resolved to distributor when zone has distributor', function (): void {
    $distributor = User::factory()->create(['role' => UserRole::Distributor]);
    $zoneWithDist = Zone::factory()->create(['distributor_id' => $distributor->id]);

    $customer = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $zoneWithDist->id,
    ]);

    $sale = Sale::factory()->create([
        'status'           => SaleStatus::Confirmed,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $customer->id,
        'zone_id'          => $zoneWithDist->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'ARS',
        'total_amount'     => '50000.0000',
        'total_currency'   => 'ARS',
    ]);

    $method = PaymentMethod::where('code', 'cash')->first();

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '20000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('cash_destination', 'distributor');

    $payment = Payment::where('sale_id', $sale->id)->first();
    expect($payment->cash_destination)->toBe('distributor');
    expect($payment->cash_destination_dist_id)->toBe($distributor->id);
});

it('account balance is updated after payment is registered', function (): void {
    $method = PaymentMethod::where('code', 'transfer_dermacells')->first();

    $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '50000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
            'reference'         => 'CBU-TEST',
        ])
        ->assertStatus(201);

    $balance = CustomerAccountBalance::where('customer_id', $this->customer->id)->first();
    expect($balance)->not->toBeNull();
    expect((float) $balance->balance_ars)->toBe(50000.0);
    expect((float) $balance->balance_usd)->toBe(0.0);
});

it('returns 422 when transfer_dermacells is missing reference field', function (): void {
    $method = PaymentMethod::where('code', 'transfer_dermacells')->first();

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '50000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
            // missing 'reference'
        ]);

    $response->assertStatus(422);
});

it('returns 422 when cheque is missing check_number', function (): void {
    $method = PaymentMethod::where('code', 'check')->first();

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '30000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-08',
            // missing check_number, check_bank, check_due_date
        ]);

    $response->assertStatus(422);
});

it('advance payment is registered with is_advance flag', function (): void {
    $method = PaymentMethod::where('code', 'cash')->first();

    $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/payments', [
            'sale_id'           => $this->sale->id,
            'payment_method_id' => $method->id,
            'amount'            => '10000',
            'currency'          => 'ARS',
            'payment_date'      => '2026-05-01',
            'is_advance'        => true,
        ])
        ->assertStatus(201)
        ->assertJsonPath('is_advance', true);

    expect(Payment::where('is_advance', true)->exists())->toBeTrue();
});
