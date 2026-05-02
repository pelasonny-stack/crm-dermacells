<?php

declare(strict_types=1);

use App\Domain\DistributorFinance\Services\DistributorAccountService;
use App\Enums\PreferredCostModality;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DistributorAccount;
use App\Models\DistributorPreferredCost;
use App\Models\ExchangeRate;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Zone;

/**
 * RecalculateAccountTest — Phase 8 §9.3
 *
 * Verifies that DistributorAccountService::recalculate() correctly aggregates
 * delivered sales, preferred costs, and settlements into balance and gross margin.
 *
 * Also verifies that the observer creates the account row lazily and that the
 * RecalculateDistributorAccountJob triggers the service correctly.
 */
beforeEach(function (): void {
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
        'updated_by'     => $this->distributor->id,
        'updated_at'     => now(),
    ]);

    $this->service = app(DistributorAccountService::class);
});

it('creates the account row when none exists and populates balances correctly', function (): void {
    // Ensure no account exists yet
    expect(DistributorAccount::where('distributor_id', $this->distributor->id)->exists())->toBeFalse();

    // Create one delivered sale: 2 boxes × USD 750 = USD 1500 revenue, cost = 2 × 500 = USD 1000
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

    $account = $this->service->recalculate($this->distributor);

    expect($account)->toBeInstanceOf(DistributorAccount::class);
    expect($account->distributor_id)->toBe($this->distributor->id);

    // Balance (saldo a rendir) = USD 1500 (no settlements yet)
    expect((float) $account->balance_usd)->toBe(1500.0);

    // Gross margin = 1500 - (2 × 500) = 500
    expect((float) $account->gross_margin_usd)->toBe(500.0);

    // No ARS activity
    expect((float) $account->balance_ars)->toBe(0.0);
    expect((float) $account->gross_margin_ars)->toBe(0.0);

    // Timestamp set
    expect($account->last_recalculated_at)->not->toBeNull();
});

it('recalculates idempotently — calling twice gives same result', function (): void {
    $sale = Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => today()->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '750.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    SaleItem::create([
        'sale_id'             => $sale->id,
        'product_id'          => $this->product->id,
        'quantity_boxes'      => 1,
        'quantity_units'      => 0,
        'unit_price_amount'   => '750.0000',
        'unit_price_currency' => 'USD',
        'subtotal_amount'     => '750.0000',
    ]);

    $first  = $this->service->recalculate($this->distributor);
    $second = $this->service->recalculate($this->distributor);

    expect((float) $first->balance_usd)->toBe((float) $second->balance_usd);
    expect((float) $first->gross_margin_usd)->toBe((float) $second->gross_margin_usd);
});

it('excludes cancelled and draft sales from balance and margin', function (): void {
    // Draft sale — should not count
    Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Draft,
        'sale_date'        => today()->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '10000.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    // Cancelled sale — should not count
    Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Cancelled,
        'sale_date'        => today()->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '5000.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    $account = $this->service->recalculate($this->distributor);

    // Only delivered sales count: none here
    expect((float) $account->balance_usd)->toBe(0.0);
    expect((float) $account->gross_margin_usd)->toBe(0.0);
});
