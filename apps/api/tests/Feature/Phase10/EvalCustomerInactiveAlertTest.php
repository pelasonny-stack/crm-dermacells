<?php

declare(strict_types=1);

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Enums\EvolutionState;
use App\Enums\UserRole;
use App\Jobs\Evolution\EvalCustomerInactiveAlertJob;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseEvolutionMetric;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 10 — EvalCustomerInactiveAlertTest
 *
 * Customer with last purchase 65 days ago and frequency=30d:
 * 65 > 30*2=60 → inactive. Alert fires to Seller + Distributor.
 */
beforeEach(function (): void {
    Notification::fake();
});

it('fires customer_inactive alert to seller and distributor when last purchase is 65d ago (freq=30d)', function (): void {
    $distributor = User::factory()->create(['role' => UserRole::Distributor, 'is_active' => true]);
    $seller      = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $zone        = Zone::factory()->create(['distributor_id' => $distributor->id]);

    $customer = Customer::factory()->create([
        'assigned_seller_id'      => $seller->id,
        'zone_id'                 => $zone->id,
        'purchase_frequency_days' => 30,
        'is_active'               => true,
    ]);

    $product = Product::factory()->create();

    PurchaseEvolutionMetric::factory()->inactive(65, 30)->create([
        'customer_id' => $customer->id,
        'product_id'  => $product->id,
    ]);

    (new EvalCustomerInactiveAlertJob())->handle(app(AlertDispatcher::class));

    // Seller gets an alert
    $sellerAlert = Alert::where('alert_type', 'customer_inactive')
        ->where('target_user_id', $seller->id)
        ->where('reference_entity_id', $customer->id)
        ->first();

    expect($sellerAlert)->not->toBeNull();
    expect($sellerAlert->severity)->toBe('warning');

    // Distributor gets an alert too
    $distributorAlert = Alert::where('alert_type', 'customer_inactive')
        ->where('target_user_id', $distributor->id)
        ->where('reference_entity_id', $customer->id)
        ->first();

    expect($distributorAlert)->not->toBeNull();
});

it('does NOT fire inactive alert for customers in zones without distributor', function (): void {
    // Expect alert only to Seller when no distributor exists
    $seller   = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $zone     = Zone::factory()->create(['distributor_id' => null]);
    $customer = Customer::factory()->create([
        'assigned_seller_id'      => $seller->id,
        'zone_id'                 => $zone->id,
        'purchase_frequency_days' => 30,
        'is_active'               => true,
    ]);
    $product = Product::factory()->create();

    PurchaseEvolutionMetric::factory()->inactive(65, 30)->create([
        'customer_id' => $customer->id,
        'product_id'  => $product->id,
    ]);

    (new EvalCustomerInactiveAlertJob())->handle(app(AlertDispatcher::class));

    // Only seller alert
    $alerts = Alert::where('alert_type', 'customer_inactive')
        ->where('reference_entity_id', $customer->id)
        ->get();

    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->target_user_id)->toBe($seller->id);
});

it('does NOT fire inactive alert for deactivated customers', function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $zone     = Zone::factory()->create();
    $customer = Customer::factory()->create([
        'assigned_seller_id'      => $seller->id,
        'zone_id'                 => $zone->id,
        'purchase_frequency_days' => 30,
        'is_active'               => false,  // deactivated
    ]);
    $product = Product::factory()->create();

    PurchaseEvolutionMetric::factory()->inactive(65, 30)->create([
        'customer_id' => $customer->id,
        'product_id'  => $product->id,
    ]);

    (new EvalCustomerInactiveAlertJob())->handle(app(AlertDispatcher::class));

    $count = Alert::where('alert_type', 'customer_inactive')
        ->where('reference_entity_id', $customer->id)
        ->count();

    expect($count)->toBe(0);
});
