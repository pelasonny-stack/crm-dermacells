<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * Verifies that the Idempotency-Key middleware on POST /invoices replays the
 * cached response on duplicate (key, body) requests.
 */
beforeEach(function (): void {
    config()->set('services.xubio.client_id', '');

    $this->director = User::factory()->create(['role' => \App\Enums\UserRole::Director]);
    $this->seller   = User::factory()->create(['role' => \App\Enums\UserRole::Seller]);
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = PaymentTerm::factory()->create(['days_to_due' => 0]);
    $this->billing = CustomerBillingEntity::factory()->create(['customer_id' => $this->customer->id]);
    $this->sale = Sale::factory()->create([
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '750.0000',
        'total_currency'   => 'USD',
    ]);
});

it('replays the cached response when same Idempotency-Key + body is sent twice', function (): void {
    $key = (string) Str::uuid();
    $body = [
        'sale_id'           => $this->sale->id,
        'billing_entity_id' => $this->billing->id,
        'voucher_type'      => 'B',
    ];

    $first = $this->actingAs($this->director, 'sanctum')
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/invoices', $body);

    $first->assertStatus(202);
    $firstId = $first->json('data.id');
    expect($firstId)->not->toBeNull();

    $second = $this->actingAs($this->director, 'sanctum')
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/invoices', $body);

    $second->assertStatus(202);
    expect($second->headers->get('Idempotency-Replayed'))->toBe('true');
    expect($second->json('data.id'))->toBe($firstId);
});
