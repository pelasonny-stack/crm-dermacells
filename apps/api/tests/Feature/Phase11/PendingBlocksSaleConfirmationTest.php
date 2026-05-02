<?php

declare(strict_types=1);

use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Stock\ReserveStockOnSaleConfirmAction;
use App\Domain\Authorizations\Services\AuthorizationService;
use App\Enums\AuthorizationType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Exceptions\OperationBlockedByPendingAuthorizationException;
use App\Models\AuthorizationRequest;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Zone;

beforeEach(function (): void {
    $this->zone         = Zone::factory()->create(['distributor_id' => null]);
    $this->seller       = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $this->director     = User::factory()->create(['role' => UserRole::Director, 'is_active' => true]);
    $this->customer     = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->product      = Product::factory()->create();
    $this->paymentTerms = PaymentTerm::factory()->create(['days_to_due' => 0]);

    $this->sale = Sale::factory()->create([
        'status'           => SaleStatus::Draft,
        'seller_id'        => $this->seller->id,
        'customer_id'      => $this->customer->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '750.0000',
        'total_currency'   => 'USD',
    ]);

    SaleItem::factory()->create([
        'sale_id'             => $this->sale->id,
        'product_id'          => $this->product->id,
        'quantity_boxes'      => 1,
        'quantity_units'      => 0,
        'unit_price_amount'   => '750.0000',
        'unit_price_currency' => 'USD',
        'subtotal_amount'     => '750.0000',
    ]);
});

it('confirm endpoint returns 409 when pending authorization exists for the sale', function (): void {
    // Create a pending authorization linked to this sale
    AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'sale_id'        => $this->sale->id,
        'requested_by'   => $this->seller->id,
        'current_value'  => '750.0000',
        'proposed_value' => '680.0000',
        'value_currency' => 'USD',
        'reason'         => 'Descuento especial para este cliente.',
        'status'         => 'pending',
    ]);

    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/confirm");

    // Must return 409 PENDING_AUTHORIZATION_EXISTS, not 200
    $response->assertStatus(409);
    expect($response->json('code'))->toBe('PENDING_AUTHORIZATION_EXISTS');

    // Sale must remain draft
    expect($this->sale->fresh()->status)->toBe(SaleStatus::Draft);
});

it('ConfirmSaleAction::assertNoPendingAuthorizations throws the correct exception', function (): void {
    // Create pending authorization
    AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'sale_id'        => $this->sale->id,
        'requested_by'   => $this->seller->id,
        'current_value'  => '750.0000',
        'proposed_value' => '600.0000',
        'reason'         => 'Test unit.',
        'status'         => 'pending',
    ]);

    $action = app(ConfirmSaleAction::class);

    expect(fn () => $action->execute($this->sale, $this->seller))
        ->toThrow(OperationBlockedByPendingAuthorizationException::class);
});

it('sale can be confirmed after authorization is approved', function (): void {
    // Setup pending authorization
    $authRequest = AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'sale_id'        => $this->sale->id,
        'requested_by'   => $this->seller->id,
        'current_value'  => '750.0000',
        'proposed_value' => '680.0000',
        'value_currency' => 'USD',
        'reason'         => 'Descuento especial.',
        'status'         => 'pending',
    ]);

    // Mock stock reservation to avoid stock-not-found errors in this test
    $this->mock(ReserveStockOnSaleConfirmAction::class, function ($mock): void {
        $mock->shouldReceive('execute')->once()->andReturn(null);
    });

    // Approve the request
    app(AuthorizationService::class)->approve($authRequest, $this->director);

    // Now confirm should succeed (no pending authorization)
    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/confirm");

    $response->assertStatus(200);
    expect($this->sale->fresh()->status)->toBe(SaleStatus::Confirmed);
});

it('sale with no authorization requests can be confirmed normally', function (): void {
    // No authorizations linked to this sale
    $this->mock(ReserveStockOnSaleConfirmAction::class, function ($mock): void {
        $mock->shouldReceive('execute')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/confirm");

    $response->assertStatus(200);
});

it('rejected authorization does not block sale confirmation', function (): void {
    // Create a REJECTED authorization for this sale
    AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'sale_id'        => $this->sale->id,
        'requested_by'   => $this->seller->id,
        'current_value'  => '750.0000',
        'proposed_value' => '600.0000',
        'reason'         => 'Rechazada — no debería bloquear.',
        'status'         => 'rejected',
        'resolved_by'    => $this->director->id,
        'resolved_at'    => now(),
        'rejection_reason' => 'No aplica.',
    ]);

    $this->mock(ReserveStockOnSaleConfirmAction::class, function ($mock): void {
        $mock->shouldReceive('execute')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->seller, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/confirm");

    $response->assertStatus(200);
});
