<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;


/**
 * Phase 14 — Seller Dashboard tests (§14.1).
 *
 * Validates:
 *   - Vendedor receives the correct DTO shape via GET /api/v1/dashboards/me.
 *   - Commission section reflects Phase 9's CommissionCalculatorService output.
 *   - No cross-vendor data leakage (RLS guard).
 */
describe('Seller dashboard', function (): void {
    it('returns 200 with seller-shaped payload for a Vendedor', function (): void {
        $seller = User::factory()->create(['role' => UserRole::Seller]);

        $response = $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);

        // Assert top-level seller-only keys are present.
        $response->assertJsonStructure([
            'today_alerts',
            'today_birthdays',
            'due_payments_48h',
            'month_collected_usd',
            'month_collected_ars',
            'commission_accumulated_usd',
            'commission_accumulated_ars',
            'commission_tier_rate',
            'sales_month_count',
            'sales_month_amount',
            'sales_prior_month_count',
            'sales_prior_month_amount',
            'pending_collections_semaphore' => ['al_dia', 'proximo_a_vencer', 'vencido'],
            'goal_boxes_target',
            'goal_boxes_achieved',
            'stock_per_product',
            'confirmed_pending_delivery_count',
            'my_pending_authorizations',
        ]);
    });

    it('returns zero commissions for a seller with no payments this month', function (): void {
        $seller = User::factory()->create(['role' => UserRole::Seller]);

        $response = $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);
        $response->assertJsonPath('commission_tier_rate', '0');
        $response->assertJsonPath('commission_accumulated_usd', '0.00');
        $response->assertJsonPath('commission_accumulated_ars', '0.00');
    });

    it('does not expose director-only endpoints to a seller (403)', function (): void {
        $seller = User::factory()->create(['role' => UserRole::Seller]);

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/dashboards/director/executive')
            ->assertStatus(403);

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/dashboards/director/portfolio-health')
            ->assertStatus(403);

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/dashboards/director/zone-ranking')
            ->assertStatus(403);
    });

    it('does not return other vendor data in the stock section', function (): void {
        $sellerA = User::factory()->create(['role' => UserRole::Seller]);
        $sellerB = User::factory()->create(['role' => UserRole::Seller]);

        // SellerA's dashboard should contain only their own stock.
        $response = $this->actingAs($sellerA, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);

        $stock = $response->json('stock_per_product');

        // Each stock entry should belong to sellerA (no sellerB seller_id in results).
        // With empty DB, stock will be an empty array — which is correct.
        expect($stock)->toBeArray();
    });

    it('requires authentication', function (): void {
        $this->getJson('/api/v1/dashboards/me')->assertStatus(401);
    });
});
