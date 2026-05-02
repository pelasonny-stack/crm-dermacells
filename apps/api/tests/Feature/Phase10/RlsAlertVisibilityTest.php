<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\User;
use App\Models\Zone;

/**
 * Phase 10 — RlsAlertVisibilityTest
 *
 * Asserts the API-layer visibility rules for the /alerts/me endpoint:
 *   - A Seller sees only their own alerts.
 *   - A Director sees all alerts.
 *   - A Seller cannot access another Seller's alert via /alerts/{id}/read
 *     (returns 404, not 403, to avoid existence leak).
 *   - /alerts/types is Director-only (403 for Seller).
 *
 * Note: Postgres RLS is also tested implicitly because all alert queries
 * go through the DB with the app.user_id / app.user_role GUCs set by
 * SetPostgresRlsContext middleware.
 */
it('seller sees only own alerts via GET /alerts/me', function (): void {
    $sellerA = User::factory()->create(['role' => UserRole::Seller]);
    $sellerB = User::factory()->create(['role' => UserRole::Seller]);
    $zone    = Zone::factory()->create();

    // Two alerts for Seller A, one for Seller B
    Alert::factory()->forUser($sellerA)->ofType('cycle_due_soon')->create();
    Alert::factory()->forUser($sellerA)->ofType('customer_inactive')->create();
    Alert::factory()->forUser($sellerB)->ofType('cycle_due_soon')->create();

    $response = $this->actingAs($sellerA, 'sanctum')
        ->getJson('/api/v1/alerts/me');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('target_user_id')->unique();

    expect($ids)->toHaveCount(1);
    expect($ids->first())->toBe($sellerA->id);
});

it('director sees all alerts via GET /alerts/me', function (): void {
    $director = User::factory()->create(['role' => UserRole::Director]);
    $sellerA  = User::factory()->create(['role' => UserRole::Seller]);
    $sellerB  = User::factory()->create(['role' => UserRole::Seller]);

    Alert::factory()->forUser($sellerA)->create();
    Alert::factory()->forUser($sellerB)->create();
    Alert::factory()->forUser($director)->create();

    $response = $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/alerts/me');

    $response->assertOk();

    // Director sees all 3 (RLS allows Director full access)
    expect(count($response->json('data')))->toBeGreaterThanOrEqual(3);
});

it('seller cannot mark another sellers alert as read (404)', function (): void {
    $sellerA = User::factory()->create(['role' => UserRole::Seller]);
    $sellerB = User::factory()->create(['role' => UserRole::Seller]);

    $alertB = Alert::factory()->forUser($sellerB)->create();

    $response = $this->actingAs($sellerA, 'sanctum')
        ->patchJson("/api/v1/alerts/{$alertB->id}/read");

    // 404 because RLS hides the row entirely — no existence leak
    $response->assertNotFound();
});

it('seller can mark own alert as read', function (): void {
    $seller = User::factory()->create(['role' => UserRole::Seller]);
    $alert  = Alert::factory()->forUser($seller)->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->patchJson("/api/v1/alerts/{$alert->id}/read");

    $response->assertOk();
    $response->assertJsonPath('id', $alert->id);

    expect(Alert::find($alert->id)->read_at)->not->toBeNull();
});

it('seller gets 403 on GET /alerts/types (Director only)', function (): void {
    $seller = User::factory()->create(['role' => UserRole::Seller]);

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/alerts/types');

    $response->assertForbidden();
});

it('director gets alert type catalog via GET /alerts/types', function (): void {
    $director = User::factory()->create(['role' => UserRole::Director]);
    $seller   = User::factory()->create(['role' => UserRole::Seller]);

    Alert::factory()->forUser($seller)->ofType('cycle_due_soon')->create();
    Alert::factory()->forUser($seller)->ofType('cycle_due_soon')->create();
    Alert::factory()->forUser($seller)->ofType('customer_inactive')->create();

    $response = $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/alerts/types');

    $response->assertOk();
    $response->assertJsonStructure(['data' => [['type', 'total_count', 'unread_count', 'last_fired_at']]]);

    $types = collect($response->json('data'))->pluck('type');

    expect($types)->toContain('cycle_due_soon');
    expect($types)->toContain('customer_inactive');
});

it('undelivered filter returns only undelivered alerts', function (): void {
    $seller = User::factory()->create(['role' => UserRole::Seller]);

    Alert::factory()->forUser($seller)->delivered()->create();
    Alert::factory()->forUser($seller)->create(); // undelivered

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/alerts/me?delivered=false');

    $response->assertOk();

    $deliveredValues = collect($response->json('data'))->pluck('delivered')->unique();

    expect($deliveredValues->all())->toEqual([false]);
});
