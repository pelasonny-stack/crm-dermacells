<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| NonDirectorBlockedTest — Phase 16
|--------------------------------------------------------------------------
|
| Verifies that Distributors and Sellers are blocked (403) from all
| Phase 16 Filament routes, including the new pages and resources.
|
*/

beforeEach(function (): void {
    Config::set('app.admin_ip_allowlist', ['127.0.0.1']);
});

dataset('non_director_roles', [
    'Seller'      => [fn () => User::factory()->seller()->create()],
    'Distributor' => [fn () => User::factory()->distributor()->create()],
]);

it('non-Directors are blocked from /admin/system-config', function (User $user): void {
    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/system-config');

    $response->assertStatus(403);
})->with('non_director_roles');

it('non-Directors are blocked from /admin/alerts-thresholds', function (User $user): void {
    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/alerts-thresholds');

    $response->assertStatus(403);
})->with('non_director_roles');

it('non-Directors are blocked from /admin/audit-log', function (User $user): void {
    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/audit-log');

    $response->assertStatus(403);
})->with('non_director_roles');

it('non-Directors are blocked from /admin/zones', function (User $user): void {
    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/zones');

    $response->assertStatus(403);
})->with('non_director_roles');

it('non-Directors are blocked from /admin/seller-monthly-goals', function (User $user): void {
    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/seller-monthly-goals');

    $response->assertStatus(403);
})->with('non_director_roles');

it('non-Directors are blocked from /admin/configurations', function (User $user): void {
    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin/configurations');

    $response->assertStatus(403);
})->with('non_director_roles');
