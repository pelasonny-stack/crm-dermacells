<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

/**
 * Phase 14 — Dashboard RLS / role access enforcement tests.
 *
 * Validates that Vendedor and Distribuidor cannot access Director-only endpoints,
 * and that unauthenticated requests are rejected.
 */
describe('Dashboard RLS and role enforcement', function (): void {
    it('Vendedor receives 403 on /dashboards/director/*', function (): void {
        $seller = User::factory()->create(['role' => UserRole::Seller]);

        $paths = [
            '/api/v1/dashboards/director/executive',
            '/api/v1/dashboards/director/portfolio-health',
            '/api/v1/dashboards/director/zone-ranking',
        ];

        foreach ($paths as $path) {
            $this->actingAs($seller, 'sanctum')
                ->getJson($path)
                ->assertStatus(403, "Expected 403 for seller on {$path}");
        }
    });

    it('Distribuidor receives 403 on /dashboards/director/*', function (): void {
        $distributor = User::factory()->create(['role' => UserRole::Distributor]);

        $paths = [
            '/api/v1/dashboards/director/executive',
            '/api/v1/dashboards/director/portfolio-health',
            '/api/v1/dashboards/director/zone-ranking',
        ];

        foreach ($paths as $path) {
            $this->actingAs($distributor, 'sanctum')
                ->getJson($path)
                ->assertStatus(403, "Expected 403 for distributor on {$path}");
        }
    });

    it('Director can access all director endpoints', function (): void {
        $director = User::factory()->create(['role' => UserRole::Director]);

        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/director/executive')
            ->assertStatus(200);

        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/director/portfolio-health')
            ->assertStatus(200);

        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/dashboards/director/zone-ranking')
            ->assertStatus(200);
    });

    it('unauthenticated request is rejected (401)', function (): void {
        $this->getJson('/api/v1/dashboards/me')->assertStatus(401);
        $this->getJson('/api/v1/dashboards/director/executive')->assertStatus(401);
    });

    it('Vendedor /dashboards/me returns seller DTO shape not director shape', function (): void {
        $seller = User::factory()->create(['role' => UserRole::Seller]);

        $response = $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/dashboards/me')
            ->assertStatus(200);

        // Seller DTO has today_alerts key; Director DTO has today_sales_count.
        $response->assertJsonStructure(['today_alerts']);
        // Ensure no director-only key leaks.
        expect($response->json('portfolio_evolution_12m'))->toBeNull();
    });
});
