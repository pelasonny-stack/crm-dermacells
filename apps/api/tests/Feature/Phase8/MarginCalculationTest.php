<?php

declare(strict_types=1);

use App\Domain\DistributorFinance\Services\DistributorMarginService;
use App\Enums\PreferredCostModality;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DistributorPreferredCost;
use App\Models\ExchangeRate;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Zone;
use Brick\Money\Money;

/**
 * MarginCalculationTest — Phase 8 §9.3
 *
 * Scenario: Sale of 5 boxes USD 750 each (= USD 3750 revenue),
 * preferred cost USD 500/box (= USD 2500 cost) → gross margin USD 1250.
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

    // Preferred cost: USD 500 fixed price per box
    DistributorPreferredCost::create([
        'distributor_id' => $this->distributor->id,
        'product_id'     => $this->product->id,
        'modality'       => PreferredCostModality::FixedPrice,
        'value'          => '500.0000',
        'currency'       => 'USD',
        'updated_by'     => $this->distributor->id,
        'updated_at'     => now(),
    ]);

    $this->service = app(DistributorMarginService::class);
});

it('calculates margin of USD 1250 for a sale of 5 boxes at USD 750 with preferred cost USD 500/box', function (): void {
    // Create a delivered sale
    $sale = Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => today()->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '3750.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    // One line item: 5 boxes × USD 750 = USD 3750
    SaleItem::create([
        'sale_id'              => $sale->id,
        'product_id'           => $this->product->id,
        'quantity_boxes'       => 5,
        'quantity_units'       => 0,
        'unit_price_amount'    => '750.0000',
        'unit_price_currency'  => 'USD',
        'subtotal_amount'      => '3750.0000',
    ]);

    $margin = $this->service->marginForSale($sale);

    expect($margin)->toBeInstanceOf(Money::class);
    expect($margin->getCurrency()->getCurrencyCode())->toBe('USD');
    // Revenue USD 3750 - Cost (5 × USD 500 = USD 2500) = USD 1250
    expect($margin->getAmount()->toFloat())->toBe(1250.0);
});

it('calculates zero margin when preferred cost equals sale price', function (): void {
    // Set preferred cost equal to base price (no profit)
    DistributorPreferredCost::where('distributor_id', $this->distributor->id)
        ->where('product_id', $this->product->id)
        ->update(['value' => '750.0000', 'modality' => PreferredCostModality::FixedPrice->value]);

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

    $margin = $this->service->marginForSale($sale);

    expect($margin->getAmount()->toFloat())->toBe(0.0);
});

it('calculates margin correctly with discount_pct modality', function (): void {
    // Override to 20% discount (cost = 750 × 0.8 = 600)
    DistributorPreferredCost::where('distributor_id', $this->distributor->id)
        ->where('product_id', $this->product->id)
        ->update(['value' => '0.2000', 'modality' => PreferredCostModality::DiscountPct->value]);

    $sale = Sale::create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'status'           => SaleStatus::Delivered,
        'sale_date'        => today()->toDateString(),
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '2250.0000',
        'total_currency'   => 'USD',
        'delegated_delivery' => false,
    ]);

    // 3 boxes at USD 750 each = USD 2250 revenue
    SaleItem::create([
        'sale_id'             => $sale->id,
        'product_id'          => $this->product->id,
        'quantity_boxes'      => 3,
        'quantity_units'      => 0,
        'unit_price_amount'   => '750.0000',
        'unit_price_currency' => 'USD',
        'subtotal_amount'     => '2250.0000',
    ]);

    $margin = $this->service->marginForSale($sale);

    // Revenue 2250, Cost 3 × 600 = 1800, Margin = 450
    expect($margin->getAmount()->toFloat())->toBe(450.0);
});
