<?php

declare(strict_types=1);

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Enums\EvolutionState;
use App\Enums\UserRole;
use App\Jobs\Evolution\EvalZoneAtRiskAlertJob;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseEvolutionMetric;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 10 — ZoneAtRiskAlertTest
 *
 * Zone with 4 out of 10 inactive customers (40% > 30% threshold) → fires.
 * Zone with 2 out of 10 inactive customers (20% < 30% threshold) → does not fire.
 */
beforeEach(function (): void {
    Notification::fake();
    config(['evolution.zone_inactive_threshold_pct' => 30]);
});

it('fires zone_at_risk alert when 40% of zone customers are inactive (threshold=30%)', function (): void {
    $distributor = User::factory()->create(['role' => UserRole::Distributor, 'is_active' => true]);
    $director    = User::factory()->create(['role' => UserRole::Director, 'is_active' => true]);
    $seller      = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $zone        = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $product     = Product::factory()->create();

    // 10 active customers in the zone
    $customers = Customer::factory()->count(10)->create([
        'zone_id'            => $zone->id,
        'assigned_seller_id' => $seller->id,
        'is_active'          => true,
    ]);

    // 4 are inactive (40%)
    $customers->take(4)->each(function (Customer $c) use ($product): void {
        PurchaseEvolutionMetric::factory()->inactive(65, 30)->create([
            'customer_id' => $c->id,
            'product_id'  => $product->id,
        ]);
    });

    // 6 are stable
    $customers->skip(4)->each(function (Customer $c) use ($product): void {
        PurchaseEvolutionMetric::factory()->inState(EvolutionState::Stable)->create([
            'customer_id' => $c->id,
            'product_id'  => $product->id,
        ]);
    });

    (new EvalZoneAtRiskAlertJob())->handle(app(AlertDispatcher::class));

    // Director gets an alert
    $directorAlert = Alert::where('alert_type', 'zone_at_risk')
        ->where('target_user_id', $director->id)
        ->where('reference_entity_id', $zone->id)
        ->first();

    expect($directorAlert)->not->toBeNull();
    expect($directorAlert->severity)->toBe('critical');
    expect($directorAlert->payload_json['inactive_pct'])->toBeGreaterThan(30.0);
    expect($directorAlert->payload_json['inactive_count'])->toBe(4);

    // Distributor also gets an alert
    $distributorAlert = Alert::where('alert_type', 'zone_at_risk')
        ->where('target_user_id', $distributor->id)
        ->where('reference_entity_id', $zone->id)
        ->first();

    expect($distributorAlert)->not->toBeNull();
});

it('does NOT fire zone_at_risk when only 20% are inactive (threshold=30%)', function (): void {
    $distributor = User::factory()->create(['role' => UserRole::Distributor, 'is_active' => true]);
    $seller      = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $zone        = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $product     = Product::factory()->create();

    $customers = Customer::factory()->count(10)->create([
        'zone_id'            => $zone->id,
        'assigned_seller_id' => $seller->id,
        'is_active'          => true,
    ]);

    // 2 inactive (20%)
    $customers->take(2)->each(function (Customer $c) use ($product): void {
        PurchaseEvolutionMetric::factory()->inactive(65, 30)->create([
            'customer_id' => $c->id,
            'product_id'  => $product->id,
        ]);
    });

    // 8 stable
    $customers->skip(2)->each(function (Customer $c) use ($product): void {
        PurchaseEvolutionMetric::factory()->inState(EvolutionState::Stable)->create([
            'customer_id' => $c->id,
            'product_id'  => $product->id,
        ]);
    });

    (new EvalZoneAtRiskAlertJob())->handle(app(AlertDispatcher::class));

    $count = Alert::where('alert_type', 'zone_at_risk')
        ->where('reference_entity_id', $zone->id)
        ->count();

    expect($count)->toBe(0);
});

it('skips zones with no active customers', function (): void {
    $zone = Zone::factory()->create(['distributor_id' => null]);
    // No customers → should not fire and not throw

    expect(fn () => (new EvalZoneAtRiskAlertJob())->handle(app(AlertDispatcher::class)))
        ->not->toThrow(\Throwable::class);

    $count = Alert::where('alert_type', 'zone_at_risk')
        ->where('reference_entity_id', $zone->id)
        ->count();

    expect($count)->toBe(0);
});
