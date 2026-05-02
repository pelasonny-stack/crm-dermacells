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
 * MonthlyCommissionComputationTest — Phase 8 §9.2
 *
 * Verifies that ComputeMonthlyCommissionsJob correctly:
 *   1. Sums delivered sales of the prior month per (distributor, seller, zone, currency).
 *   2. Creates distributor_commission_payments rows with correct amounts.
 *   3. Skips sellers with no commission config (null pct).
 *   4. Is idempotent (second run does not duplicate rows).
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

    // Commission config: 15% for this seller in this zone
    app(CommissionAssignmentService::class)->setCommissionPct(
        distributor: $this->distributor,
        seller: $this->seller,
        zone: $this->zone,
        pct: 0.15,
        setBy: $this->distributor->id,
        effectiveFrom: now()->subMonths(3),
    );
});

it('creates commission payment rows for delivered sales in prior month', function (): void {
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    // Two delivered sales in the prior month
    foreach ([750.0, 750.0] as $amount) {
        $sale = Sale::create([
            'customer_id'      => $this->customer->id,
            'seller_id'        => $this->seller->id,
            'zone_id'          => $this->zone->id,
            'status'           => SaleStatus::Delivered,
            'sale_date'        => $lastMonth->toDateString(),
            'payment_terms_id' => $this->paymentTerms->id,
            'currency'         => 'USD',
            'total_amount'     => number_format($amount, 4, '.', ''),
            'total_currency'   => 'USD',
            'delegated_delivery' => false,
        ]);

        SaleItem::create([
            'sale_id'             => $sale->id,
            'product_id'          => $this->product->id,
            'quantity_boxes'      => 1,
            'quantity_units'      => 0,
            'unit_price_amount'   => number_format($amount, 4, '.', ''),
            'unit_price_currency' => 'USD',
            'subtotal_amount'     => number_format($amount, 4, '.', ''),
        ]);
    }

    // Run the job for the prior month
    (new ComputeMonthlyCommissionsJob($lastMonth))->handle(
        app(CommissionAssignmentService::class)
    );

    $payment = DistributorCommissionPayment::where('distributor_id', $this->distributor->id)
        ->where('seller_id', $this->seller->id)
        ->where('zone_id', $this->zone->id)
        ->whereDate('period_month', $lastMonth->toDateString())
        ->first();

    expect($payment)->not->toBeNull();

    // Base = 750 + 750 = 1500
    expect((float) $payment->base_amount_amount)->toBe(1500.0);
    expect($payment->base_amount_currency)->toBe('USD');

    // Commission = 1500 × 0.15 = 225
    expect((float) $payment->commission_amount_amount)->toBe(225.0);
    expect($payment->commission_amount_currency)->toBe('USD');

    expect((float) $payment->commission_pct)->toBe(0.15);
    expect($payment->paid)->toBeFalse();
});

it('is idempotent — second run does not create duplicate rows', function (): void {
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => $lastMonth->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '750.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    $commissionService = app(CommissionAssignmentService::class);
    (new ComputeMonthlyCommissionsJob($lastMonth))->handle($commissionService);
    (new ComputeMonthlyCommissionsJob($lastMonth))->handle($commissionService);

    $count = DistributorCommissionPayment::where('distributor_id', $this->distributor->id)
        ->where('seller_id', $this->seller->id)
        ->where('zone_id', $this->zone->id)
        ->whereDate('period_month', $lastMonth->toDateString())
        ->count();

    expect($count)->toBe(1);
});

it('skips sellers with no commission config in the zone', function (): void {
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
    $unconfiguredSeller = User::factory()->create(['role' => UserRole::Seller]);

    $unconfiguredCustomer = Customer::factory()->create([
        'zone_id'            => $this->zone->id,
        'assigned_seller_id' => $unconfiguredSeller->id,
    ]);

    Sale::create([
        'customer_id'      => $unconfiguredCustomer->id,
        'seller_id'        => $unconfiguredSeller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => $lastMonth->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '750.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    (new ComputeMonthlyCommissionsJob($lastMonth))->handle(
        app(CommissionAssignmentService::class)
    );

    $exists = DistributorCommissionPayment::where('seller_id', $unconfiguredSeller->id)->exists();
    expect($exists)->toBeFalse();
});

it('only considers sales from the target month, not other months', function (): void {
    $lastMonth  = now()->subMonthNoOverflow()->startOfMonth();
    $twoMonthsAgo = now()->subMonths(2)->startOfMonth();

    // Sale from 2 months ago — should NOT be included
    Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => $twoMonthsAgo->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '99999.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    (new ComputeMonthlyCommissionsJob($lastMonth))->handle(
        app(CommissionAssignmentService::class)
    );

    // No payment created because no sales in $lastMonth
    $exists = DistributorCommissionPayment::where('distributor_id', $this->distributor->id)
        ->whereDate('period_month', $lastMonth->toDateString())
        ->exists();

    expect($exists)->toBeFalse();
});
