<?php

declare(strict_types=1);

use App\Enums\AuthorizationType;
use App\Enums\UserRole;
use App\Models\AuthorizationRequest;
use App\Models\User;
use App\Models\Zone;

beforeEach(function (): void {
    $this->zone        = Zone::factory()->create(['distributor_id' => null]);
    $this->seller      = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $this->distributor = User::factory()->create(['role' => UserRole::Distributor, 'is_active' => true]);
    $this->director    = User::factory()->create(['role' => UserRole::Director, 'is_active' => true]);

    $this->authRequest = AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'requested_by'   => $this->seller->id,
        'current_value'  => '750.0000',
        'proposed_value' => '680.0000',
        'value_currency' => 'USD',
        'reason'         => 'Solicitud de prueba para verificar permisos.',
        'status'         => 'pending',
    ]);
});

it('seller cannot resolve an authorization request (403)', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    $response->assertStatus(403);

    // Status must remain pending
    expect($this->authRequest->fresh()->status)->toBe('pending');
});

it('distributor cannot resolve an authorization request (403)', function (): void {
    $response = $this->actingAs($this->distributor, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    $response->assertStatus(403);

    // Reset GUC to director so RLS allows the SELECT (the HTTP request's
    // savepoint committed the distributor GUC into the outer transaction).
    \Illuminate\Support\Facades\DB::statement("SET LOCAL app.user_role = 'director'");
    expect($this->authRequest->fresh()->status)->toBe('pending');
});

it('unauthenticated request returns 401', function (): void {
    $response = $this->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
        'action' => 'approve',
    ]);

    $response->assertStatus(401);
});

it('director can resolve and receives 200', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    $response->assertStatus(200);
    expect($this->authRequest->fresh()->status)->toBe('approved');
});

it('seller cannot reject their own request (403)', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action'           => 'reject',
            'rejection_reason' => 'Autorreject test.',
        ]);

    $response->assertStatus(403);
    expect($this->authRequest->fresh()->status)->toBe('pending');
});
