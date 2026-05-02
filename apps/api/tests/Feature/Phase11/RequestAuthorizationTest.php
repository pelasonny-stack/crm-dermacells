<?php

declare(strict_types=1);

use App\Enums\AuthorizationType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\AuthorizationRequest;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;

beforeEach(function (): void {
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = PaymentTerm::factory()->create(['days_to_due' => 0]);
    $this->sale         = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
    ]);
});

it('vendedor can submit a price change request', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'sale_id'        => $this->sale->id,
            'current_value'  => '750.00',
            'proposed_value' => '680.00',
            'value_currency' => 'USD',
            'reason'         => 'El cliente solicitó descuento por volumen.',
        ]);

    $response->assertStatus(201);
    $body = $response->json();

    expect($body['type'])->toBe(AuthorizationType::PriceChange->value)
        ->and($body['status'])->toBe('pending')
        ->and($body['requested_by'])->toBe($this->seller->id)
        ->and($body['sale_id'])->toBe($this->sale->id);

    $this->assertDatabaseHas('authorization_requests', [
        'type'           => 'price_change',
        'status'         => 'pending',
        'requested_by'   => $this->seller->id,
        'sale_id'        => $this->sale->id,
        'proposed_value' => '680.0000',
    ]);
});

it('vendedor can submit an exchange rate change request without sale_id', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::ExchangeRateChange->value,
            'current_value'  => '1250.50',
            'proposed_value' => '1200.00',
            'reason'         => 'TC acordado con el cliente.',
        ]);

    $response->assertStatus(201);
    expect($response->json('type'))->toBe(AuthorizationType::ExchangeRateChange->value)
        ->and($response->json('sale_id'))->toBeNull();
});

it('director cannot submit an authorization request', function (): void {
    $director = User::factory()->create(['role' => UserRole::Director, 'is_active' => true]);

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'current_value'  => '750.00',
            'proposed_value' => '600.00',
            'reason'         => 'El director no deberia poder hacer esto.',
        ]);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('DIRECTOR_CANNOT_REQUEST_AUTHORIZATION');
});

it('reason field is required', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'current_value'  => '750.00',
            'proposed_value' => '600.00',
            // reason intentionally omitted
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('type field must be a valid AuthorizationType', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => 'invalid_type',
            'current_value'  => '750.00',
            'proposed_value' => '600.00',
            'reason'         => 'Test inválido.',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

it('distributor can submit a price change request', function (): void {
    $distributor = User::factory()->create(['role' => UserRole::Distributor, 'is_active' => true]);

    $response = $this->actingAs($distributor, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'current_value'  => '750.00',
            'proposed_value' => '700.00',
            'value_currency' => 'USD',
            'reason'         => 'El distribuidor negocia precio especial.',
        ]);

    $response->assertStatus(201);
    expect($response->json('status'))->toBe('pending');
});
