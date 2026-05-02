<?php

declare(strict_types=1);

use App\Domain\DistributorFinance\Services\DistributorMarginService;
use App\Enums\PreferredCostModality;
use App\Enums\UserRole;
use App\Models\DistributorPreferredCost;
use App\Models\Product;
use App\Models\User;
use Brick\Money\Money;

/**
 * PreferredCostResolutionTest — Phase 8 §9.1
 *
 * Verifies that DistributorMarginService::resolvePreferredCostPerBox()
 * returns the correct Money value for:
 *   1. fixed_price modality → USD 500 regardless of base price.
 *   2. discount_pct modality → 20% off USD 750 base → USD 600.
 *   3. No config → falls back to base price.
 */
beforeEach(function (): void {
    $this->distributor = User::factory()->create(['role' => UserRole::Distributor]);
    $this->product     = Product::factory()->create([
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
    ]);
    $this->service = app(DistributorMarginService::class);
});

it('returns fixed price USD 500 when modality is fixed_price', function (): void {
    DistributorPreferredCost::create([
        'distributor_id' => $this->distributor->id,
        'product_id'     => $this->product->id,
        'modality'       => PreferredCostModality::FixedPrice,
        'value'          => '500.0000',
        'currency'       => 'USD',
        'updated_by'     => $this->distributor->id,
        'updated_at'     => now(),
    ]);

    $cost = $this->service->resolvePreferredCostPerBox($this->distributor, $this->product);

    expect($cost)->toBeInstanceOf(Money::class);
    expect($cost->getCurrency()->getCurrencyCode())->toBe('USD');
    expect($cost->getAmount()->toFloat())->toBe(500.0);
});

it('returns USD 600 when modality is discount_pct of 20% on USD 750 base', function (): void {
    // 20% discount: value = 0.2000
    DistributorPreferredCost::create([
        'distributor_id' => $this->distributor->id,
        'product_id'     => $this->product->id,
        'modality'       => PreferredCostModality::DiscountPct,
        'value'          => '0.2000',
        'currency'       => 'USD',
        'updated_by'     => $this->distributor->id,
        'updated_at'     => now(),
    ]);

    $cost = $this->service->resolvePreferredCostPerBox($this->distributor, $this->product);

    // USD 750 × (1 − 0.20) = USD 600
    expect($cost)->toBeInstanceOf(Money::class);
    expect($cost->getCurrency()->getCurrencyCode())->toBe('USD');
    expect($cost->getAmount()->toFloat())->toBe(600.0);
});

it('falls back to base price when no preferred cost is configured', function (): void {
    // No DistributorPreferredCost row inserted
    $cost = $this->service->resolvePreferredCostPerBox($this->distributor, $this->product);

    expect($cost->getCurrency()->getCurrencyCode())->toBe('USD');
    expect($cost->getAmount()->toFloat())->toBe(750.0);
});

it('different distributors can have different costs for the same product', function (): void {
    $otherDistributor = User::factory()->create(['role' => UserRole::Distributor]);

    DistributorPreferredCost::create([
        'distributor_id' => $this->distributor->id,
        'product_id'     => $this->product->id,
        'modality'       => PreferredCostModality::FixedPrice,
        'value'          => '500.0000',
        'currency'       => 'USD',
        'updated_by'     => $this->distributor->id,
        'updated_at'     => now(),
    ]);

    // otherDistributor has no config → base price
    $cost1 = $this->service->resolvePreferredCostPerBox($this->distributor, $this->product);
    $cost2 = $this->service->resolvePreferredCostPerBox($otherDistributor, $this->product);

    expect($cost1->getAmount()->toFloat())->toBe(500.0);
    expect($cost2->getAmount()->toFloat())->toBe(750.0);
});
