<?php

declare(strict_types=1);

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 10 — AlertDispatcherIdempotencyTest
 *
 * Same (type, target, reference) dispatched twice within 24h → only ONE alert row,
 * only ONE notification fired.
 *
 * Second dispatch within 24h → skipped (cache key present).
 * Third dispatch after cache cleared → fires again.
 */
beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
});

it('inserts only one alert row when dispatched twice for same (type, target, reference) within 24h', function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone     = Zone::factory()->create();
    $customer = Customer::factory()->create([
        'assigned_seller_id' => $seller->id,
        'zone_id'            => $zone->id,
    ]);

    $dispatcher = app(AlertDispatcher::class);

    // First dispatch
    $first = $dispatcher->dispatch(
        type: 'customer_inactive',
        targets: $seller,
        reference: $customer,
        payload: ['days_inactive' => 65],
        severity: 'warning',
    );

    // Second dispatch — same type, same target, same reference
    $second = $dispatcher->dispatch(
        type: 'customer_inactive',
        targets: $seller,
        reference: $customer,
        payload: ['days_inactive' => 66],
        severity: 'warning',
    );

    expect($first)->toHaveCount(1);
    expect($second)->toHaveCount(0); // idempotency: skipped

    $totalInDb = Alert::where('alert_type', 'customer_inactive')
        ->where('target_user_id', $seller->id)
        ->where('reference_entity_id', $customer->id)
        ->count();

    expect($totalInDb)->toBe(1);
});

it('fires again after the 24h cache key expires', function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone     = Zone::factory()->create();
    $customer = Customer::factory()->create([
        'assigned_seller_id' => $seller->id,
        'zone_id'            => $zone->id,
    ]);

    $dispatcher = app(AlertDispatcher::class);

    $dispatcher->dispatch(
        type: 'customer_inactive',
        targets: $seller,
        reference: $customer,
        payload: ['days_inactive' => 65],
    );

    // Simulate cache expiry by flushing
    Cache::flush();

    $second = $dispatcher->dispatch(
        type: 'customer_inactive',
        targets: $seller,
        reference: $customer,
        payload: ['days_inactive' => 66],
    );

    expect($second)->toHaveCount(1);

    $totalInDb = Alert::where('alert_type', 'customer_inactive')
        ->where('target_user_id', $seller->id)
        ->where('reference_entity_id', $customer->id)
        ->count();

    expect($totalInDb)->toBe(2);
});

it('allows same type+target with different reference entities within 24h', function (): void {
    $seller    = User::factory()->create(['role' => UserRole::Seller]);
    $zone      = Zone::factory()->create();
    $customer1 = Customer::factory()->create(['assigned_seller_id' => $seller->id, 'zone_id' => $zone->id]);
    $customer2 = Customer::factory()->create(['assigned_seller_id' => $seller->id, 'zone_id' => $zone->id]);

    $dispatcher = app(AlertDispatcher::class);

    $r1 = $dispatcher->dispatch(type: 'cycle_due_soon', targets: $seller, reference: $customer1);
    $r2 = $dispatcher->dispatch(type: 'cycle_due_soon', targets: $seller, reference: $customer2);

    expect($r1)->toHaveCount(1);
    expect($r2)->toHaveCount(1); // different reference → different cache key → allowed

    $total = Alert::where('alert_type', 'cycle_due_soon')
        ->where('target_user_id', $seller->id)
        ->count();

    expect($total)->toBe(2);
});

it('marks the alert as delivered immediately after dispatch', function (): void {
    $seller = User::factory()->create(['role' => UserRole::Seller]);
    $zone   = Zone::factory()->create();

    $alerts = app(AlertDispatcher::class)->dispatch(
        type: 'cycle_due_soon',
        targets: $seller,
        reference: null,
        payload: [],
    );

    expect($alerts)->toHaveCount(1);
    expect($alerts[0]->delivered)->toBeTrue();
    expect($alerts[0]->delivered_at)->not->toBeNull();
});
