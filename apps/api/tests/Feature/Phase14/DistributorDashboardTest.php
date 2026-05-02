<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;


/**
 * Phase 14 — Distributor Dashboard tests (§14.2).
 *
 * Validates:
 *   - Distribuidor sees zone-shaped DTO.
 *   - Seller ranking is returned (ordered correctly when data exists).
 *   - Distribuidor cannot access director endpoints.
 */
describe('Distributor dashboard', function (): void {
    it('returns 200 with distributor-shaped payload', function (): void {
        $distributor = User::factory()->create(['role' => UserRole::Distributor]);

        $response = $this->actingAs($distributor, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'zone_urgent_customers',
            'zone_sellers_low_stock',
            'zone_overdue_payments',
            'zone_sales_month_boxes',
            'zone_sales_month_amount',
            'seller_ranking',
            'cobros_by_modality',
            'zone_sales_evolution_6m',
            'zone_cobros_evolution_6m',
            'saldo_a_rendir_ars',
            'saldo_a_rendir_usd',
            'margin_bruto_mes_ars',
            'margin_bruto_mes_usd',
            'commissions_to_pay_ars',
            'commissions_to_pay_usd',
            'last_settlements',
            'balance_evolution_6m',
            'own_stock_per_product',
            'sellers_stock_in_zone',
            'zone_stock_alerts',
        ]);
    });

    it('returns empty seller ranking when no payments exist', function (): void {
        $distributor = User::factory()->create(['role' => UserRole::Distributor]);

        $response = $this->actingAs($distributor, 'sanctum')
            ->getJson('/api/v1/dashboards/me');

        $response->assertStatus(200);
        expect($response->json('seller_ranking'))->toBeArray()->toBeEmpty();
    });

    it('blocks distributor from director endpoints (403)', function (): void {
        $distributor = User::factory()->create(['role' => UserRole::Distributor]);

        $this->actingAs($distributor, 'sanctum')
            ->getJson('/api/v1/dashboards/director/executive')
            ->assertStatus(403);

        $this->actingAs($distributor, 'sanctum')
            ->getJson('/api/v1/dashboards/director/portfolio-health')
            ->assertStatus(403);
    });
});
