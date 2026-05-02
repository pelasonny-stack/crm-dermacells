<?php

declare(strict_types=1);

use App\Domain\Evolution\Services\EvolutionEngine;
use App\Enums\EvolutionState;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseEvolutionMetric;
use App\Models\ScheduledAction;
use App\Models\User;

/**
 * Phase 10 — EvolutionStateClassificationTest
 *
 * Tests the pure stateFor() classification function of EvolutionEngine.
 * All assertions use transient (unsaved) PurchaseEvolutionMetric instances
 * to verify the state logic in isolation from the database.
 */
beforeEach(function (): void {
    $seller         = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id'      => $seller->id,
        'purchase_frequency_days' => 30,
    ]);
    $this->product  = Product::factory()->create();
    $this->engine   = app(EvolutionEngine::class);
});

// ─── first_purchase ──────────────────────────────────────────────────────────

it('classifies purchase_count=1 as first_purchase regardless of intervals', function (): void {
    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 1,
        'avg_interval_days'  => null,
        'last_interval_days' => null,
        'last_purchase_date' => now()->subDays(5)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::FirstPurchase);
});

// ─── increasing ──────────────────────────────────────────────────────────────

it('classifies last_interval < avg_interval as increasing', function (): void {
    // avg=30, last=20 → 20 < 30 * 0.80 (lower bound) → increasing
    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 3,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 20,
        'last_purchase_date' => now()->subDays(20)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::Increasing);
});

// ─── stable ──────────────────────────────────────────────────────────────────

it('classifies last_interval within avg ±20% as stable', function (): void {
    // avg=30, last=30 → exactly on avg → stable
    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 5,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 30,
        'last_purchase_date' => now()->subDays(10)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::Stable);
});

it('classifies last_interval at upper band edge (avg*1.20) as stable', function (): void {
    // avg=30, last=36 → 36 == 30*1.20 → still within band
    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 4,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 36,
        'last_purchase_date' => now()->subDays(10)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::Stable);
});

// ─── decreasing ──────────────────────────────────────────────────────────────

it('classifies last_interval > avg*1.20 as decreasing', function (): void {
    // avg=30, last=37 → 37 > 30*1.20=36 → decreasing
    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 4,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 37,
        'last_purchase_date' => now()->subDays(10)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::Decreasing);
});

// ─── inactive ────────────────────────────────────────────────────────────────

it('classifies days_since_last > 2x frequency as inactive', function (): void {
    // frequency=30d, days_since=65 → 65 > 30*2=60 → inactive
    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 3,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 65,
        'last_purchase_date' => now()->subDays(65)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::Inactive);
});

it('does not classify as inactive when days_since_last equals exactly 2x frequency', function (): void {
    // frequency=30d, days_since=60 → 60 == 30*2, NOT > → not inactive
    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 3,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 60,
        'last_purchase_date' => now()->subDays(60)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    // At exactly 2x, the condition (> 60) is false → not inactive
    expect($state)->not->toBe(EvolutionState::Inactive);
});

// ─── scheduled override ──────────────────────────────────────────────────────

it('overrides inactive state with scheduled when an active scheduled_action exists', function (): void {
    ScheduledAction::factory()->create([
        'customer_id'    => $this->customer->id,
        'is_resolved'    => false,
        'scheduled_date' => now()->addDays(5)->toDateString(),
        'note'           => 'Follow up call',
        'created_by'     => User::factory()->create(['role' => UserRole::Director])->id,
    ]);

    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 3,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 65,
        'last_purchase_date' => now()->subDays(65)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::Scheduled);
});

it('does not override decreasing state with scheduled when scheduled_action is resolved', function (): void {
    ScheduledAction::factory()->create([
        'customer_id'    => $this->customer->id,
        'is_resolved'    => true,
        'scheduled_date' => now()->subDays(1)->toDateString(),
        'note'           => 'Done',
        'created_by'     => User::factory()->create(['role' => UserRole::Director])->id,
    ]);

    $metric = new PurchaseEvolutionMetric([
        'purchase_count'     => 4,
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 37,
        'last_purchase_date' => now()->subDays(10)->toDateString(),
    ]);

    $state = $this->engine->stateFor($this->customer, $this->product, $metric);

    expect($state)->toBe(EvolutionState::Decreasing);
});
