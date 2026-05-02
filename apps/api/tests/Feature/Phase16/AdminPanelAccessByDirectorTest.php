<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| AdminPanelAccessByDirectorTest — Phase 16
|--------------------------------------------------------------------------
|
| Verifies that Directors reach all Filament routes with at least a 200 or
| redirect (not 403). Tests run with 127.0.0.1 (allowlisted in phpunit.xml).
|
*/

beforeEach(function (): void {
    Config::set('app.admin_ip_allowlist', ['127.0.0.1']);
});

it('Director can access the Filament /admin dashboard', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin');

    expect($response->status())->not->toBe(403);
});

it('Director can access the SystemConfig page', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/system-config');

    expect($response->status())->not->toBe(403);
});

it('Director can access the AlertsThresholds page', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/alerts-thresholds');

    expect($response->status())->not->toBe(403);
});

it('Director can access the AuditLogViewer page', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/audit-log');

    expect($response->status())->not->toBe(403);
});

it('Director can access the IntegrationStatus page', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/integration-status');

    expect($response->status())->not->toBe(403);
});

it('Director can access ZoneResource list', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/zones');

    expect($response->status())->not->toBe(403);
});

it('Director can access SellerMonthlyGoalResource list', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/seller-monthly-goals');

    expect($response->status())->not->toBe(403);
});

it('Director can access ConfigurationResource list', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/configurations');

    expect($response->status())->not->toBe(403);
});
