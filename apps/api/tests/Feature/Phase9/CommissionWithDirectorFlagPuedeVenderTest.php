<?php

declare(strict_types=1);

/**
 * Phase 9 — Director with can_sell=true STILL earns zero commission (§12.3).
 *
 * §2.2 (verbatim): "La única diferencia entre Directores con y sin flag es el
 * acceso al módulo de ventas y stock propio. Ningún Director percibe comisiones."
 *
 * §12.3 (verbatim): "Los Directores no perciben comisiones en ningún caso,
 * independientemente del flag 'puede vender'."
 *
 * These tests establish that:
 *   1. A Director with can_sell=true gets zero commission from the service.
 *   2. The HTTP endpoints also enforce this with 403, not just the service.
 *   3. Even when payments exist for such a Director's sales, the result is zero.
 */

use App\Domain\Commissions\Seller\Services\CommissionCalculatorService;
use App\Models\CommissionTier;
use App\Models\ExchangeRate;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Service layer tests
// ---------------------------------------------------------------------------

it('CommissionCalculatorService returns zero and is_director=true for Director with can_sell=true', function (): void {
    $director = User::factory()->directorWithSell()->create();
    $month    = Carbon::create(2026, 5, 1);

    $tiers  = CommissionTier::activeOn($month)->get();
    $result = (new CommissionCalculatorService($tiers))->calculateForMonth($director, $month);

    expect($result->is_director)->toBeTrue();
    expect($result->accumulated_usd->getAmount()->isZero())->toBeTrue();
    expect($result->tier_rate->isZero())->toBeTrue();
    expect($result->commission_ars->getAmount()->isZero())->toBeTrue();
    expect($result->commission_usd->getAmount()->isZero())->toBeTrue();
});

it('Director with can_sell=true gets zero commission even when payments exist', function (): void {
    if (! Schema::hasTable('payments')) {
        $this->markTestSkipped('payments table not yet migrated (Phase 7 running in parallel).');
    }

    $director = User::factory()->directorWithSell()->create();
    $month    = Carbon::create(2026, 5, 1);
    $zone     = Zone::factory()->create(['distributor_id' => null]);
    $rate     = ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.000000', 'rate_date' => '2026-05-10']);
    $sale     = Sale::factory()->create([
        'seller_id' => $director->id,
        'zone_id'   => $zone->id,
        'currency'  => 'USD',
    ]);

    // Insert a large payment that would reach the 15% tier
    DB::table('payments')->insert([
        'id'               => Str::uuid()->toString(),
        'sale_id'          => $sale->id,
        'amount'           => '50000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-15',
        'exchange_rate_id' => $rate->id,
        'is_advance'       => false,
        'reversed'         => false,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $tiers  = CommissionTier::activeOn($month)->get();
    $result = (new CommissionCalculatorService($tiers))->calculateForMonth($director, $month);

    // The service must short-circuit on role=Director before querying payments
    expect($result->is_director)->toBeTrue();
    expect($result->accumulated_usd->getAmount()->isZero())->toBeTrue();
    expect($result->commission_usd->getAmount()->isZero())->toBeTrue();
    expect($result->breakdown_by_zone)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// HTTP endpoint tests
// ---------------------------------------------------------------------------

it('GET /commissions/me returns 403 for Director with can_sell=true', function (): void {
    $director = User::factory()->directorWithSell()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/commissions/me?month=2026-05')
        ->assertStatus(403)
        ->assertJsonPath('code', 'DIRECTOR_NO_COMMISSION');
});

it('GET /commissions/me/breakdown returns 403 for Director with can_sell=true', function (): void {
    $director = User::factory()->directorWithSell()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/commissions/me/breakdown?month=2026-05')
        ->assertStatus(403)
        ->assertJsonPath('code', 'DIRECTOR_NO_COMMISSION');
});

it('Director with can_sell=true CAN query another seller via GET /commissions/{seller_id}', function (): void {
    // This verifies that can_sell Directors retain Director-level privileges
    // for read operations — they can still inspect others' commissions.
    $director = User::factory()->directorWithSell()->create();
    $seller   = User::factory()->seller()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson("/api/v1/commissions/{$seller->id}?month=2026-05")
        ->assertStatus(200)
        ->assertJsonPath('meta.seller_id', $seller->id);
});

it('result from GET /commissions/{director_id} shows is_director=true when Director queries self', function (): void {
    $director = User::factory()->directorWithSell()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson("/api/v1/commissions/{$director->id}?month=2026-05")
        ->assertStatus(200)
        ->assertJsonPath('data.is_director', true)
        ->assertJsonPath('data.commission_ars', '0.00')
        ->assertJsonPath('data.commission_usd', '0.00');
});
