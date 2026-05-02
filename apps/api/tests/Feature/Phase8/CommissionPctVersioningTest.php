<?php

declare(strict_types=1);

use App\Domain\DistributorFinance\Services\CommissionAssignmentService;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Jobs\DistributorFinance\ComputeMonthlyCommissionsJob;
use App\Models\Customer;
use App\Models\DistributorCommissionPayment;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Zone;

/**
 * CommissionPctVersioningTest — Phase 8 §9.2
 *
 * Verifies that:
 *   1. The active commission percentage is resolved as-of the first day of
 *      the period being computed.
 *   2. Changing the pct mid-month (after the 1st) does NOT affect the prior
 *      period's computation — historicals are unaffected.
 *   3. currentPctFor() correctly resolves MAX(effective_from) <= target_date.
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
    $this->service      = app(CommissionAssignmentService::class);
});

it('currentPctFor resolves the most recently effective pct for a given date', function (): void {
    // 10% effective from 3 months ago
    $this->service->setCommissionPct(
        distributor: $this->distributor,
        seller: $this->seller,
        zone: $this->zone,
        pct: 0.10,
        setBy: $this->distributor->id,
        effectiveFrom: now()->subMonths(3),
    );

    // 15% effective from last month
    $this->service->setCommissionPct(
        distributor: $this->distributor,
        seller: $this->seller,
        zone: $this->zone,
        pct: 0.15,
        setBy: $this->distributor->id,
        effectiveFrom: now()->subMonthNoOverflow()->startOfMonth(),
    );

    // As of today → 15% (most recent)
    $pctToday = $this->service->currentPctFor($this->distributor, $this->seller, $this->zone, today());
    expect($pctToday)->toBe(0.15);

    // As of 3 months ago → 10%
    $pctEarlier = $this->service->currentPctFor(
        $this->distributor,
        $this->seller,
        $this->zone,
        now()->subMonths(3),
    );
    expect($pctEarlier)->toBe(0.10);
});

it('changing pct mid-month does not affect prior period commission computation', function (): void {
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    // 10% was active for all of last month
    $this->service->setCommissionPct(
        distributor: $this->distributor,
        seller: $this->seller,
        zone: $this->zone,
        pct: 0.10,
        setBy: $this->distributor->id,
        effectiveFrom: $lastMonth->copy()->subDays(10),
    );

    // A sale from last month
    $sale = Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => $lastMonth->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '1000.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    SaleItem::create([
        'sale_id'             => $sale->id,
        'product_id'          => $this->product->id,
        'quantity_boxes'      => 1,
        'quantity_units'      => 0,
        'unit_price_amount'   => '1000.0000',
        'unit_price_currency' => 'USD',
        'subtotal_amount'     => '1000.0000',
    ]);

    // NOW change the pct to 20% AFTER the month end — effective this month
    $this->service->setCommissionPct(
        distributor: $this->distributor,
        seller: $this->seller,
        zone: $this->zone,
        pct: 0.20,
        setBy: $this->distributor->id,
        effectiveFrom: today(),
    );

    // Compute commissions for last month — should still use 10%
    (new ComputeMonthlyCommissionsJob($lastMonth))->handle($this->service);

    $payment = DistributorCommissionPayment::where('distributor_id', $this->distributor->id)
        ->where('seller_id', $this->seller->id)
        ->whereDate('period_month', $lastMonth->toDateString())
        ->first();

    expect($payment)->not->toBeNull();
    // 10% of 1000 = 100, NOT 20% = 200
    expect((float) $payment->commission_pct)->toBe(0.10);
    expect((float) $payment->commission_amount_amount)->toBe(100.0);
});

it('returns null when no pct is configured for the tuple', function (): void {
    $pct = $this->service->currentPctFor($this->distributor, $this->seller, $this->zone, today());
    expect($pct)->toBeNull();
});

it('setting same effective_from twice updates pct (director correction)', function (): void {
    $effectiveFrom = today();

    $this->service->setCommissionPct(
        distributor: $this->distributor,
        seller: $this->seller,
        zone: $this->zone,
        pct: 0.10,
        setBy: $this->distributor->id,
        effectiveFrom: $effectiveFrom,
    );

    // Correct it immediately
    $this->service->setCommissionPct(
        distributor: $this->distributor,
        seller: $this->seller,
        zone: $this->zone,
        pct: 0.12,
        setBy: $this->distributor->id,
        effectiveFrom: $effectiveFrom,
    );

    $pct = $this->service->currentPctFor($this->distributor, $this->seller, $this->zone, today());
    expect($pct)->toBe(0.12);

    // Only one row for this effective_from
    $count = \App\Models\SellerCommissionConfig::where('distributor_id', $this->distributor->id)
        ->where('seller_id', $this->seller->id)
        ->where('zone_id', $this->zone->id)
        ->whereDate('effective_from', $effectiveFrom->toDateString())
        ->count();

    expect($count)->toBe(1);
});
