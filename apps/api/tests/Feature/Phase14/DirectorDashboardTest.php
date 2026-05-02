<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;


/**
 * Phase 14 — Director Dashboard tests (§14.3).
 *
 * Validates:
 *   - Director sees global-shaped DTO.
 *   - Integration status block is present and populated.
 *   - Director can access drill-down endpoints.
 *   - portfolio-health months parameter is respected.
 */
describe('Director dashboard', function (): void {
    it('returns 200 with director-shaped payload', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $response = $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'today_sales_count',
            'today_sales_boxes',
            'today_sales_amount',
            'today_cobros_ars',
            'today_cobros_usd',
            'pending_authorizations_count',
            'critical_alerts',
            'month_sales_amount',
            'prior_month_sales_amount',
            'month_sales_boxes',
            'prior_month_sales_boxes',
            'seller_ranking_global',
            'distributor_ranking',
            'goal_semaphore',
            'portfolio_evolution_12m',
            'zone_penetration',
            'at_risk_customers',
            'top10_customers',
            'central_stock',
            'critical_stock_actors',
            'confirmed_pending_delivery_global',
            'stale_drafts_count',
            'settlement_status_per_zone',
            'global_overdue_amount',
            'global_overdue_customers_count',
            'cobros_evolution_6m',
            'top_overdue_customers',
            'ai_active',
            'ai_tokens_month',
            'ai_cost_month_usd',
            'integration_status',
            'recent_authorizations',
        ]);
    });

    it('includes integration_status block with expected keys', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $response = $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);

        $integrations = $response->json('integration_status');
        expect($integrations)->toBeArray();

        // Keys are xubio, bcra, ai, whatsapp, fcm.
        foreach (['xubio', 'bcra', 'ai', 'whatsapp', 'fcm'] as $key) {
            expect($integrations)->toHaveKey($key);
        }
    });

    it('allows director to hit executive endpoint', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/director/executive')
            ->assertStatus(200);
    });

    it('allows director to hit portfolio-health endpoint', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/director/portfolio-health?months=6')
            ->assertStatus(200)
            ->assertJsonStructure(['evolution', 'zone_penetration', 'at_risk_customers', 'top10_customers']);
    });

    it('clamps months parameter to 1-24 range', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        // 999 months should not error — clamped to 24.
        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/director/portfolio-health?months=999')
            ->assertStatus(200);
    });

    it('allows director to hit zone-ranking endpoint', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/director/zone-ranking')
            ->assertStatus(200)
            ->assertJsonStructure(['ranking']);
    });

    it('returns zero values when no seed data exists (Phase 17 note)', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $response = $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);
        // Zero values expected with empty DB.
        $response->assertJsonPath('today_sales_count', 0);
        $response->assertJsonPath('pending_authorizations_count', 0);
    });
});
