<?php

declare(strict_types=1);

use App\Actions\Sales\CancelSaleAction;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerCreditBalance;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;

/**
 * Phase 7 — CreditBalanceFromCancellationTest
 *
 * Verifies §7.5: when a Director cancels a sale that has active payments
 * (and no invoice), customer_credit_balances rows are created.
 *
 * Also verifies §7.4: if the sale had both ARS and USD payments, two separate
 * credit rows are created — one per currency.
 */

beforeEach(function (): void {
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->director = User::factory()->create(['role' => UserRole::Director]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->terms    = PaymentTerm::factory()->create(['days_to_due' => 0]);
});

it('cancelling a sale with ARS payment creates an ARS credit balance', function (): void {
    $sale = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'currency'         => 'ARS',
        'total_amount'     => '50000.0000',
        'total_currency'   => 'ARS',
    ]);

    $cashMethod = PaymentMethod::where('code', 'cash')->first();
    Payment::create([
        'sale_id'           => $sale->id,
        'customer_id'       => $this->customer->id,
        'payment_method_id' => $cashMethod->id,
        'amount_amount'     => '50000.0000',
        'amount_currency'   => 'ARS',
        'payment_date'      => '2026-05-08',
        'cash_destination'  => 'dermacells',
        'reversed'          => false,
        'is_advance'        => false,
        'recorded_by'       => $this->seller->id,
    ]);

    // Director cancels via API
    $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/sales/{$sale->id}", [
            'reason' => 'Director cancelling with ARS payment to test credit balance.',
        ])
        ->assertStatus(204);

    $credits = CustomerCreditBalance::where('customer_id', $this->customer->id)->get();
    expect($credits)->toHaveCount(1);
    expect($credits->first()->amount_currency)->toBe('ARS');
    expect((float) $credits->first()->amount_amount)->toBe(50000.0);
    expect($credits->first()->origin_sale_id)->toBe($sale->id);
    expect($credits->first()->applied_to_sale_id)->toBeNull();
});

it('cancelling a sale with no payments creates NO credit balance', function (): void {
    $sale = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'currency'         => 'ARS',
        'total_amount'     => '50000.0000',
        'total_currency'   => 'ARS',
    ]);

    // No payments registered — cancel by seller (no payments = allowed)
    $this->actingAs($this->seller, 'sanctum')
        ->deleteJson("/api/v1/sales/{$sale->id}", [
            'reason' => 'Cancelling draft with no payments.',
        ])
        ->assertStatus(204);

    $credits = CustomerCreditBalance::where('customer_id', $this->customer->id)->get();
    expect($credits)->toHaveCount(0);
});

it('cancelling a sale with ARS and USD payments creates two credit rows', function (): void {
    $sale = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'currency'         => 'ARS',
        'total_amount'     => '100000.0000',
        'total_currency'   => 'ARS',
    ]);

    $cashMethod     = PaymentMethod::where('code', 'cash')->first();
    $transferMethod = PaymentMethod::where('code', 'transfer_dermacells')->first();

    // ARS payment
    Payment::create([
        'sale_id'           => $sale->id,
        'customer_id'       => $this->customer->id,
        'payment_method_id' => $cashMethod->id,
        'amount_amount'     => '50000.0000',
        'amount_currency'   => 'ARS',
        'payment_date'      => '2026-05-08',
        'cash_destination'  => 'dermacells',
        'reversed'          => false,
        'is_advance'        => false,
        'recorded_by'       => $this->seller->id,
    ]);

    // USD payment
    Payment::create([
        'sale_id'           => $sale->id,
        'customer_id'       => $this->customer->id,
        'payment_method_id' => $transferMethod->id,
        'amount_amount'     => '300.0000',
        'amount_currency'   => 'USD',
        'payment_date'      => '2026-05-08',
        'reversed'          => false,
        'is_advance'        => false,
        'reference'         => 'WIRE-001',
        'recorded_by'       => $this->seller->id,
    ]);

    $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/sales/{$sale->id}", [
            'reason' => 'Director cancelling multi-currency sale.',
        ])
        ->assertStatus(204);

    $credits = CustomerCreditBalance::where('customer_id', $this->customer->id)
        ->orderBy('amount_currency')
        ->get();

    expect($credits)->toHaveCount(2);

    $arsCreditAmounts  = $credits->where('amount_currency', 'ARS');
    $usdCreditAmounts  = $credits->where('amount_currency', 'USD');

    expect($arsCreditAmounts)->toHaveCount(1);
    expect((float) $arsCreditAmounts->first()->amount_amount)->toBe(50000.0);

    expect($usdCreditAmounts)->toHaveCount(1);
    expect((float) $usdCreditAmounts->first()->amount_amount)->toBe(300.0);
});

it('reversed payments are excluded when computing credit balance from cancellation', function (): void {
    $sale = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->terms->id,
        'currency'         => 'ARS',
        'total_amount'     => '50000.0000',
        'total_currency'   => 'ARS',
    ]);

    $cashMethod = PaymentMethod::where('code', 'cash')->first();

    // This payment was reversed — should NOT count
    Payment::create([
        'sale_id'           => $sale->id,
        'customer_id'       => $this->customer->id,
        'payment_method_id' => $cashMethod->id,
        'amount_amount'     => '50000.0000',
        'amount_currency'   => 'ARS',
        'payment_date'      => '2026-05-01',
        'cash_destination'  => 'dermacells',
        'reversed'          => true,  // already reversed
        'is_advance'        => false,
        'recorded_by'       => $this->seller->id,
    ]);

    $this->actingAs($this->director, 'sanctum')
        ->deleteJson("/api/v1/sales/{$sale->id}", [
            'reason' => 'Cancellation with reversed payment only.',
        ])
        ->assertStatus(204);

    // No active payments → no credit balance
    $credits = CustomerCreditBalance::where('customer_id', $this->customer->id)->get();
    expect($credits)->toHaveCount(0);
});
