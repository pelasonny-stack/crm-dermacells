<?php

declare(strict_types=1);

use App\Domain\Evolution\Services\EvolutionEngine;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseEvolutionMetric;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — RecomputeEngineTest
 *
 * Seeds delivered sales, runs EvolutionEngine::recompute(), and asserts that
 * purchase_evolution_metrics rows are correctly populated.
 *
 * Note: The materialized view (mv_customer_product_purchases) is created by
 * migration 000003 and refreshed inside recompute(). Tests run on an in-memory
 * Postgres (sqlite is not used — the project uses PostgreSQL via RefreshDatabase).
 */
beforeEach(function (): void {
    $this->seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone           = \App\Models\Zone::factory()->create();
    $this->customer = Customer::factory()->create([
        'assigned_seller_id'      => $this->seller->id,
        'zone_id'                 => $zone->id,
        'purchase_frequency_days' => 30,
    ]);
    $this->product = Product::factory()->create();
    $this->engine  = app(EvolutionEngine::class);
});

it('creates a metric row with correct purchase_count after two delivered sales', function (): void {
    // Seed two delivered sales 30 days apart
    $paymentTerm = \App\Models\PaymentTerm::factory()->create();

    $sale1 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Delivered,
        'sale_date'       => now()->subDays(60)->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);

    SaleItem::factory()->create([
        'sale_id'    => $sale1->id,
        'product_id' => $this->product->id,
    ]);

    $sale2 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Delivered,
        'sale_date'       => now()->subDays(30)->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);

    SaleItem::factory()->create([
        'sale_id'    => $sale2->id,
        'product_id' => $this->product->id,
    ]);

    $affected = $this->engine->recompute();

    expect($affected)->toBeGreaterThanOrEqual(1);

    $metric = PurchaseEvolutionMetric::where('customer_id', $this->customer->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect($metric)->not->toBeNull();
    expect($metric->purchase_count)->toBe(2);
    expect($metric->avg_interval_days)->toBeFloat();
    expect((float) $metric->avg_interval_days)->toBeGreaterThan(0.0);
});

it('classifies a customer with interval=37d (avg=30d) as decreasing', function (): void {
    $paymentTerm = \App\Models\PaymentTerm::factory()->create();

    // Sale 1: 67 days ago
    $s1 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Delivered,
        'sale_date'       => now()->subDays(67)->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);
    SaleItem::factory()->create(['sale_id' => $s1->id, 'product_id' => $this->product->id]);

    // Sale 2: 37 days ago (interval from s1 = 30d)
    $s2 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Delivered,
        'sale_date'       => now()->subDays(37)->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);
    SaleItem::factory()->create(['sale_id' => $s2->id, 'product_id' => $this->product->id]);

    // Sale 3: today (interval from s2 = 37d > avg*1.20=36)
    $s3 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Delivered,
        'sale_date'       => now()->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);
    SaleItem::factory()->create(['sale_id' => $s3->id, 'product_id' => $this->product->id]);

    $this->engine->recompute();

    $metric = PurchaseEvolutionMetric::where('customer_id', $this->customer->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect($metric->evolution_state->value)->toBe('decreasing');
});

it('only counts delivered sales (not draft or confirmed)', function (): void {
    $paymentTerm = \App\Models\PaymentTerm::factory()->create();

    // One delivered + one draft — only the delivered should count
    $s1 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Delivered,
        'sale_date'       => now()->subDays(10)->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);
    SaleItem::factory()->create(['sale_id' => $s1->id, 'product_id' => $this->product->id]);

    $s2 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Draft,
        'sale_date'       => now()->subDays(5)->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);
    SaleItem::factory()->create(['sale_id' => $s2->id, 'product_id' => $this->product->id]);

    $this->engine->recompute();

    $metric = PurchaseEvolutionMetric::where('customer_id', $this->customer->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect($metric->purchase_count)->toBe(1);
    expect($metric->evolution_state->value)->toBe('first_purchase');
});

it('is idempotent: running recompute twice yields same metric row (upsert)', function (): void {
    $paymentTerm = \App\Models\PaymentTerm::factory()->create();

    $s1 = Sale::factory()->create([
        'customer_id'     => $this->customer->id,
        'seller_id'       => $this->seller->id,
        'zone_id'         => $this->customer->zone_id,
        'status'          => SaleStatus::Delivered,
        'sale_date'       => now()->subDays(10)->toDateString(),
        'payment_terms_id' => $paymentTerm->id,
    ]);
    SaleItem::factory()->create(['sale_id' => $s1->id, 'product_id' => $this->product->id]);

    $this->engine->recompute();
    $this->engine->recompute();

    $count = PurchaseEvolutionMetric::where('customer_id', $this->customer->id)
        ->where('product_id', $this->product->id)
        ->count();

    expect($count)->toBe(1);
});
