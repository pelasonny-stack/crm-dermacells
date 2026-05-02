<?php

declare(strict_types=1);

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Enums\EvolutionState;
use App\Enums\UserRole;
use App\Jobs\Evolution\EvalNextCycleDueAlertJob;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseEvolutionMetric;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 10 — EvalNextCycleDueAlertTest
 *
 * Customer with frequency=30d and last purchase 25 days ago:
 * the alert window opens at day 25 (30 - 5) and closes at day 29.
 * The job should fire an alert to the assigned Seller.
 */
beforeEach(function (): void {
    Notification::fake();
});

it('fires cycle_due_soon alert when customer is 25d into a 30d cycle (5d before due)', function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone     = \App\Models\Zone::factory()->create();
    $customer = Customer::factory()->create([
        'assigned_seller_id'      => $seller->id,
        'zone_id'                 => $zone->id,
        'purchase_frequency_days' => 30,
        'is_active'               => true,
    ]);
    $product = Product::factory()->create();

    PurchaseEvolutionMetric::factory()->create([
        'customer_id'        => $customer->id,
        'product_id'         => $product->id,
        'evolution_state'    => EvolutionState::Stable,
        'last_purchase_date' => now()->subDays(25)->toDateString(),
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 30,
        'purchase_count'     => 5,
    ]);

    (new EvalNextCycleDueAlertJob())->handle(app(AlertDispatcher::class));

    $alert = Alert::where('alert_type', 'cycle_due_soon')
        ->where('target_user_id', $seller->id)
        ->where('reference_entity_id', $customer->id)
        ->first();

    expect($alert)->not->toBeNull();
    expect($alert->severity)->toBe('info');
    expect($alert->payload_json['days_until_due'])->toBe(5);
});

it('does NOT fire cycle_due_soon when customer has more than 5 days left', function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone     = \App\Models\Zone::factory()->create();
    $customer = Customer::factory()->create([
        'assigned_seller_id'      => $seller->id,
        'zone_id'                 => $zone->id,
        'purchase_frequency_days' => 30,
        'is_active'               => true,
    ]);
    $product = Product::factory()->create();

    PurchaseEvolutionMetric::factory()->create([
        'customer_id'        => $customer->id,
        'product_id'         => $product->id,
        'evolution_state'    => EvolutionState::Stable,
        'last_purchase_date' => now()->subDays(10)->toDateString(), // 20 days remaining
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 30,
        'purchase_count'     => 5,
    ]);

    (new EvalNextCycleDueAlertJob())->handle(app(AlertDispatcher::class));

    $count = Alert::where('alert_type', 'cycle_due_soon')
        ->where('target_user_id', $seller->id)
        ->count();

    expect($count)->toBe(0);
});

it('does NOT fire cycle_due_soon for inactive customers', function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone     = \App\Models\Zone::factory()->create();
    $customer = Customer::factory()->create([
        'assigned_seller_id'      => $seller->id,
        'zone_id'                 => $zone->id,
        'purchase_frequency_days' => 30,
        'is_active'               => true,
    ]);
    $product = Product::factory()->create();

    PurchaseEvolutionMetric::factory()->create([
        'customer_id'        => $customer->id,
        'product_id'         => $product->id,
        'evolution_state'    => EvolutionState::Inactive,
        'last_purchase_date' => now()->subDays(65)->toDateString(),
        'avg_interval_days'  => 30.0,
        'last_interval_days' => 65,
        'purchase_count'     => 3,
    ]);

    (new EvalNextCycleDueAlertJob())->handle(app(AlertDispatcher::class));

    $count = Alert::where('alert_type', 'cycle_due_soon')
        ->where('target_user_id', $seller->id)
        ->count();

    expect($count)->toBe(0);
});
