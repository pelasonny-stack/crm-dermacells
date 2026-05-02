<?php

declare(strict_types=1);

use App\Enums\AuthorizationType;
use App\Enums\UserRole;
use App\Models\AuthorizationRequest;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->zone        = Zone::factory()->create(['distributor_id' => null]);
    $this->sellerA     = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $this->sellerB     = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $this->distributor = User::factory()->create(['role' => UserRole::Distributor, 'is_active' => true]);
    $this->director    = User::factory()->create(['role' => UserRole::Director, 'is_active' => true]);

    // Create requests for sellerA and sellerB
    $this->requestA = AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'requested_by'   => $this->sellerA->id,
        'current_value'  => '750.0000',
        'proposed_value' => '680.0000',
        'value_currency' => 'USD',
        'reason'         => 'Solicitud del Vendedor A.',
        'status'         => 'pending',
    ]);

    $this->requestB = AuthorizationRequest::create([
        'type'           => AuthorizationType::ExchangeRateChange,
        'requested_by'   => $this->sellerB->id,
        'current_value'  => '1250.0000',
        'proposed_value' => '1200.0000',
        'reason'         => 'Solicitud del Vendedor B.',
        'status'         => 'pending',
    ]);

    $this->distributorRequest = AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'requested_by'   => $this->distributor->id,
        'current_value'  => '750.0000',
        'proposed_value' => '720.0000',
        'value_currency' => 'USD',
        'reason'         => 'Solicitud del Distribuidor.',
        'status'         => 'pending',
    ]);
});

it('seller A sees only their own requests via GET /authorizations', function (): void {
    $response = $this->actingAs($this->sellerA, 'sanctum')
        ->getJson('/api/v1/authorizations');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->toArray();

    expect($ids)->toContain($this->requestA->id)
        ->and($ids)->not->toContain($this->requestB->id)
        ->and($ids)->not->toContain($this->distributorRequest->id);
});

it('seller B sees only their own requests', function (): void {
    $response = $this->actingAs($this->sellerB, 'sanctum')
        ->getJson('/api/v1/authorizations');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->toArray();

    expect($ids)->toContain($this->requestB->id)
        ->and($ids)->not->toContain($this->requestA->id);
});

it('distributor sees only their own requests', function (): void {
    $response = $this->actingAs($this->distributor, 'sanctum')
        ->getJson('/api/v1/authorizations');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->toArray();

    expect($ids)->toContain($this->distributorRequest->id)
        ->and($ids)->not->toContain($this->requestA->id)
        ->and($ids)->not->toContain($this->requestB->id);
});

it('director sees all requests across all requesters', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->getJson('/api/v1/authorizations');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->toArray();

    expect($ids)->toContain($this->requestA->id)
        ->and($ids)->toContain($this->requestB->id)
        ->and($ids)->toContain($this->distributorRequest->id);
});

it('RLS prevents sellerA from reading sellerB request at DB level', function (): void {
    // Verify at the DB layer that RLS filters correctly for sellerA
    DB::transaction(function (): void {
        DB::statement("SET LOCAL app.user_role = 'seller'");
        DB::statement("SET LOCAL app.user_id = '{$this->sellerA->id}'");

        $visible = AuthorizationRequest::whereIn('id', [
            $this->requestA->id,
            $this->requestB->id,
        ])->pluck('id')->toArray();

        expect($visible)->toContain($this->requestA->id)
            ->and($visible)->not->toContain($this->requestB->id);
    });
});
