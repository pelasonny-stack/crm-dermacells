<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

/**
 * Tests for the Idempotency-Key middleware.
 *
 * The middleware is applied to POST /api/v1/sales (and Phase 6 /payments, /invoices).
 * These tests exercise:
 *   1. Replay: same (user_id, key) + same body → cached response with Idempotency-Replayed: true
 *   2. Mismatch: same (user_id, key) + different body → 422 IDEMPOTENCY_KEY_BODY_MISMATCH
 *   3. Missing header → 422 IDEMPOTENCY_KEY_REQUIRED
 *   4. Invalid UUID → 422 IDEMPOTENCY_KEY_INVALID
 */
beforeEach(function (): void {
    $this->seller      = User::factory()->create(['role' => UserRole::Seller]);
    $this->director    = User::factory()->create(['role' => UserRole::Director, 'can_sell' => true]);
    $this->zone        = \App\Models\Zone::factory()->create(['distributor_id' => null]);
    $this->customer    = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->product      = Product::factory()->create(['base_price_amount' => '750.0000']);
    $this->paymentTerms = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);

    $this->validPayload = [
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'sale_date'        => '2026-05-06',
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'items'            => [
            [
                'product_id'      => $this->product->id,
                'quantity_boxes'  => 1,
                'quantity_units'  => 0,
            ],
        ],
    ];
});

it('returns 422 when Idempotency-Key header is missing', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', $this->validPayload);
    // No Idempotency-Key header

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('IDEMPOTENCY_KEY_REQUIRED');
});

it('returns 422 when Idempotency-Key is not a valid UUID v4', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', $this->validPayload, [
            'Idempotency-Key' => 'not-a-uuid',
        ]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('IDEMPOTENCY_KEY_INVALID');
});

it('replays the cached response when same key and same body are sent twice', function (): void {
    $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    // First request — should process and store
    $first = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', $this->validPayload, ['Idempotency-Key' => $key]);

    $first->assertStatus(201);
    expect($first->headers->get('Idempotency-Replayed'))->toBeNull();

    // Second request — same key, same body → replay
    $second = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', $this->validPayload, ['Idempotency-Key' => $key]);

    $second->assertStatus(201);
    expect($second->headers->get('Idempotency-Replayed'))->toBe('true');

    // Both responses have the same body (same sale ID)
    expect($first->json('data.id'))->toBe($second->json('data.id'));

    // Only ONE sale was created in the DB
    $count = Sale::where('customer_id', $this->customer->id)->count();
    expect($count)->toBe(1);
});

it('returns 422 IDEMPOTENCY_KEY_BODY_MISMATCH when same key is sent with different body', function (): void {
    $key = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    // First request — stores the key
    $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', $this->validPayload, ['Idempotency-Key' => $key]);

    // Second request — same key, but different body
    $differentPayload = array_merge($this->validPayload, ['sale_date' => '2026-12-31']);

    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', $differentPayload, ['Idempotency-Key' => $key]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('IDEMPOTENCY_KEY_BODY_MISMATCH');
});

it('expired idempotency keys are treated as non-existent', function (): void {
    $key = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    // Manually insert an expired record
    IdempotencyKey::create([
        'user_id'           => $this->seller->id,
        'key'               => $key,
        'request_path'      => 'v1/sales',
        'request_body_hash' => hash('sha256', 'old body'),
        'response_body'     => '{"data":{"id":"old"}}',
        'response_status'   => 201,
        'created_at'        => now()->subHours(25),
        'expires_at'        => now()->subHours(1), // expired
    ]);


    // Request should be treated as fresh (expired record deleted, request processed)
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/sales', $this->validPayload, ['Idempotency-Key' => $key]);

    // Expired record should be gone (deleted by the SQL-level delete in middleware)
    expect(IdempotencyKey::where('key', $key)->where('expires_at', '<', now())->count())->toBe(0);
});
