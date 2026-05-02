<?php

declare(strict_types=1);

/**
 * Phase 9 — Commission Endpoint Authorization Tests.
 *
 * Verifies:
 *   - Vendedor can GET /commissions/me (own data).
 *   - Director gets 403 on /commissions/me (§12.3).
 *   - Director can GET /commissions/{seller_id} for any seller.
 *   - Vendedor gets 403 when trying /commissions/{other_seller_id}.
 *   - /commissions/me/breakdown returns zone breakdown for Vendedor.
 *   - Month parameter defaults to current month.
 *   - Invalid month parameter falls back gracefully.
 *
 * Payment insertion is guarded: if the payments table does not exist
 * the DB queries in the service return an empty result, meaning commission
 * values will be zero — that is acceptable for auth/routing tests.
 */

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Carbon;

// ---------------------------------------------------------------------------
// GET /api/v1/commissions/me
// ---------------------------------------------------------------------------

it('allows Vendedor to access GET /commissions/me', function (): void {
    $seller = User::factory()->seller()->create();

    $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/commissions/me?month=' . Carbon::now()->format('Y-m'))
        ->assertStatus(200)
        ->assertJsonStructure([
            'data' => ['accumulated_usd', 'tier_rate', 'commission_ars', 'commission_usd', 'is_director'],
            'meta' => ['month'],
        ]);
});

it('allows Distribuidor to access GET /commissions/me', function (): void {
    $distributor = User::factory()->distributor()->create();

    $this->actingAs($distributor, 'sanctum')
        ->getJson('/api/v1/commissions/me?month=' . Carbon::now()->format('Y-m'))
        ->assertStatus(200)
        ->assertJsonPath('data.is_director', false);
});

it('returns 403 for Director on GET /commissions/me', function (): void {
    $director = User::factory()->director()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/commissions/me?month=' . Carbon::now()->format('Y-m'))
        ->assertStatus(403)
        ->assertJsonPath('code', 'DIRECTOR_NO_COMMISSION');
});

it('returns 403 for Director with can_sell=true on GET /commissions/me', function (): void {
    $director = User::factory()->directorWithSell()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/commissions/me?month=' . Carbon::now()->format('Y-m'))
        ->assertStatus(403)
        ->assertJsonPath('code', 'DIRECTOR_NO_COMMISSION');
});

// ---------------------------------------------------------------------------
// GET /api/v1/commissions/{seller_id}
// ---------------------------------------------------------------------------

it('allows Director to GET /commissions/{seller_id} for any seller', function (): void {
    $director = User::factory()->director()->create();
    $seller   = User::factory()->seller()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson("/api/v1/commissions/{$seller->id}?month=" . Carbon::now()->format('Y-m'))
        ->assertStatus(200)
        ->assertJsonStructure([
            'data' => ['accumulated_usd', 'tier_rate', 'commission_ars', 'commission_usd'],
            'meta' => ['month', 'seller_id'],
        ])
        ->assertJsonPath('meta.seller_id', $seller->id);
});

it('returns 403 when Vendedor tries to GET /commissions/{other_seller_id}', function (): void {
    $sellerA = User::factory()->seller()->create();
    $sellerB = User::factory()->seller()->create();

    $this->actingAs($sellerA, 'sanctum')
        ->getJson("/api/v1/commissions/{$sellerB->id}?month=" . Carbon::now()->format('Y-m'))
        ->assertStatus(403)
        ->assertJsonPath('code', 'DIRECTOR_ONLY');
});

it('returns 403 when Distribuidor tries to GET /commissions/{seller_id}', function (): void {
    $distributor = User::factory()->distributor()->create();
    $seller      = User::factory()->seller()->create();

    $this->actingAs($distributor, 'sanctum')
        ->getJson("/api/v1/commissions/{$seller->id}?month=" . Carbon::now()->format('Y-m'))
        ->assertStatus(403);
});

it('returns 404 when Director queries a non-existent seller_id', function (): void {
    $director = User::factory()->director()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/commissions/00000000-0000-4000-8000-000000000000?month=2026-05')
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// GET /api/v1/commissions/me/breakdown
// ---------------------------------------------------------------------------

it('allows Vendedor to access GET /commissions/me/breakdown', function (): void {
    $seller = User::factory()->seller()->create();

    $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/commissions/me/breakdown?month=' . Carbon::now()->format('Y-m'))
        ->assertStatus(200)
        ->assertJsonStructure([
            'data' => ['accumulated_usd', 'tier_rate', 'commission_ars', 'commission_usd', 'breakdown_by_zone'],
            'meta' => ['month'],
        ]);
});

it('returns 403 for Director on GET /commissions/me/breakdown', function (): void {
    $director = User::factory()->director()->create();

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/commissions/me/breakdown?month=2026-05')
        ->assertStatus(403)
        ->assertJsonPath('code', 'DIRECTOR_NO_COMMISSION');
});

// ---------------------------------------------------------------------------
// Month parameter handling
// ---------------------------------------------------------------------------

it('defaults to current month when month param is absent', function (): void {
    $seller = User::factory()->seller()->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/commissions/me')
        ->assertStatus(200);

    $expectedMonth = Carbon::now()->format('Y-m');
    expect($response->json('meta.month'))->toBe($expectedMonth);
});

it('returns current month on invalid month format', function (): void {
    $seller = User::factory()->seller()->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/commissions/me?month=not-a-date')
        ->assertStatus(200);

    $expectedMonth = Carbon::now()->format('Y-m');
    expect($response->json('meta.month'))->toBe($expectedMonth);
});

// ---------------------------------------------------------------------------
// Unauthenticated requests
// ---------------------------------------------------------------------------

it('returns 401 for unauthenticated GET /commissions/me', function (): void {
    $this->getJson('/api/v1/commissions/me')
        ->assertStatus(401);
});
