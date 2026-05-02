<?php

declare(strict_types=1);

use App\Actions\Stock\CommitStockOnDeliverAction;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidSaleTransitionException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\StateMachines\SaleTransitionGuard;

/**
 * §5.7 delegated delivery: when delegated_delivery=true, the Distributor
 * of the zone is the expected deliverer. A Seller must not be able to deliver
 * a delegated sale without the Distributor or Director role.
 */
beforeEach(function (): void {
    $this->director     = User::factory()->create(['role' => UserRole::Director]);
    $this->distributor  = User::factory()->create(['role' => UserRole::Distributor]);
    $this->seller       = User::factory()->create(['role' => UserRole::Seller]);
    $this->zone         = \App\Models\Zone::factory()->create(['distributor_id' => $this->distributor->id]);
    $this->customer     = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);
});

it('distributor can deliver a delegated sale', function (): void {
    $sale = Sale::factory()->create([
        'status'                  => SaleStatus::Confirmed,
        'seller_id'               => $this->seller->id,
        'customer_id'             => $this->customer->id,
        'zone_id'                 => $this->zone->id,
        'payment_terms_id'        => $this->paymentTerms->id,
        'currency'                => 'USD',
        'total_amount'            => '750.0000',
        'total_currency'          => 'USD',
        'delegated_delivery'      => true,
        'delegated_distributor_id' => $this->distributor->id,
    ]);

    $this->mock(CommitStockOnDeliverAction::class, fn ($m) => $m->shouldReceive('execute')->once());

    $response = $this->actingAs($this->distributor, 'sanctum')
        ->patchJson("/api/v1/sales/{$sale->id}/deliver");

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe(SaleStatus::Delivered->value);
});

it('seller cannot deliver a delegated sale', function (): void {
    $sale = Sale::factory()->create([
        'status'                  => SaleStatus::Confirmed,
        'seller_id'               => $this->seller->id,
        'customer_id'             => $this->customer->id,
        'zone_id'                 => $this->zone->id,
        'payment_terms_id'        => $this->paymentTerms->id,
        'currency'                => 'USD',
        'total_amount'            => '750.0000',
        'total_currency'          => 'USD',
        'delegated_delivery'      => true,
        'delegated_distributor_id' => $this->distributor->id,
    ]);

    // Guard must throw — seller is not the expected deliverer
    $guard = new SaleTransitionGuard($sale, $this->seller);
    expect(fn () => $guard->assertCanTransitionTo(SaleStatus::Delivered))
        ->toThrow(InvalidSaleTransitionException::class);
});

it('director can deliver a delegated sale', function (): void {
    $sale = Sale::factory()->create([
        'status'                  => SaleStatus::Confirmed,
        'seller_id'               => $this->seller->id,
        'customer_id'             => $this->customer->id,
        'zone_id'                 => $this->zone->id,
        'payment_terms_id'        => $this->paymentTerms->id,
        'currency'                => 'USD',
        'total_amount'            => '750.0000',
        'total_currency'          => 'USD',
        'delegated_delivery'      => true,
        'delegated_distributor_id' => $this->distributor->id,
    ]);

    $this->mock(CommitStockOnDeliverAction::class, fn ($m) => $m->shouldReceive('execute')->once());

    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/sales/{$sale->id}/deliver");

    $response->assertStatus(200);
});
