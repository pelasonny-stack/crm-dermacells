<?php

declare(strict_types=1);

use App\Domain\Payments\Services\CashDestinationDecision;
use App\Domain\Payments\Services\CashDestinationResolver;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\Zone;

/**
 * Phase 7 — CashDestinationTest
 *
 * Unit tests for CashDestinationResolver logic per §7.2.
 *
 *   - Zone with Distributor → destination = 'distributor', distributorId = UUID
 *   - Zona directa (no Distributor) → destination = 'dermacells', distributorId = null
 *   - Customer with null zone_id → destination = 'dermacells' (safety fallback)
 */

it('resolves to distributor when customer zone has a distributor', function (): void {
    $distributor = User::factory()->create(['role' => UserRole::Distributor]);
    $zone        = Zone::factory()->create(['distributor_id' => $distributor->id]);
    $seller      = User::factory()->create(['role' => UserRole::Seller]);
    $customer    = Customer::factory()->create([
        'zone_id'            => $zone->id,
        'assigned_seller_id' => $seller->id,
    ]);

    $resolver  = new CashDestinationResolver();
    $decision  = $resolver->resolveFor($customer);

    expect($decision)->toBeInstanceOf(CashDestinationDecision::class);
    expect($decision->destination)->toBe('distributor');
    expect($decision->distributorId)->toBe($distributor->id);
    expect($decision->isForDistributor())->toBeTrue();
    expect($decision->isForDermacells())->toBeFalse();
});

it('resolves to dermacells when customer zone has no distributor (zona directa)', function (): void {
    $zone     = Zone::factory()->create(['distributor_id' => null]);
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $customer = Customer::factory()->create([
        'zone_id'            => $zone->id,
        'assigned_seller_id' => $seller->id,
    ]);

    $resolver = new CashDestinationResolver();
    $decision = $resolver->resolveFor($customer);

    expect($decision->destination)->toBe('dermacells');
    expect($decision->distributorId)->toBeNull();
    expect($decision->isForDermacells())->toBeTrue();
    expect($decision->isForDistributor())->toBeFalse();
});

it('resolves to dermacells when customer has no zone assigned', function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $customer = Customer::factory()->create([
        'zone_id'            => null,
        'assigned_seller_id' => $seller->id,
    ]);

    $resolver = new CashDestinationResolver();
    $decision = $resolver->resolveFor($customer);

    expect($decision->destination)->toBe('dermacells');
    expect($decision->distributorId)->toBeNull();
});

it('CashDestinationDecision::dermacells is a static constructor', function (): void {
    $decision = CashDestinationDecision::dermacells();
    expect($decision->destination)->toBe('dermacells');
    expect($decision->distributorId)->toBeNull();
});

it('CashDestinationDecision::distributor carries the distributor id', function (): void {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    $decision = CashDestinationDecision::distributor($uuid);
    expect($decision->destination)->toBe('distributor');
    expect($decision->distributorId)->toBe($uuid);
});
