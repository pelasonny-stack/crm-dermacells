<?php

declare(strict_types=1);

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Filament Admin Access Gate Tests
|--------------------------------------------------------------------------
|
| The /admin panel is restricted by two independent gates:
|
|   1. Role gate: only users with role = 'director' may enter.
|      Sellers and Distributors receive 403.
|
|   2. IP allowlist gate: Directors connecting from an IP not in
|      ADMIN_IP_ALLOWLIST also receive 403.
|
| ADMIN_IP_ALLOWLIST is set to "127.0.0.1" in phpunit.xml.
| The test client sends requests from 127.0.0.1 by default in Laravel.
|
*/

it('Seller is denied access to /admin with 403', function (): void {
    $seller = User::factory()->seller()->create();

    $response = $this->actingAs($seller)->get('/admin');

    $response->assertStatus(403);
});

it('Distributor is denied access to /admin with 403', function (): void {
    $distributor = User::factory()->distributor()->create();

    $response = $this->actingAs($distributor)->get('/admin');

    $response->assertStatus(403);
});

it('Director is denied access to /admin from a non-allowlisted IP', function (): void {
    $director = User::factory()->director()->create();

    // Simulate request arriving from an IP that is not in the allowlist.
    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '192.168.100.99'])
        ->get('/admin');

    $response->assertStatus(403);
});

it('Director can access /admin from an allowlisted IP', function (): void {
    $director = User::factory()->director()->create();

    // 127.0.0.1 is in the allowlist (set in phpunit.xml ADMIN_IP_ALLOWLIST).
    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/admin');

    // Filament may redirect to a dashboard page (302) or render it (200).
    // Either indicates the gate was passed — only 403 would mean denial.
    expect($response->status())->not->toBe(403);
});

it('unauthenticated user is blocked from /admin', function (): void {
    $response = $this->get('/admin');

    // AdminAccessGate aborts with 403 for unauthenticated users before
    // Filament's own auth redirect can fire. This is intentional — the
    // gate is a defence-in-depth layer that runs before Filament's guard.
    $response->assertStatus(403);
});
