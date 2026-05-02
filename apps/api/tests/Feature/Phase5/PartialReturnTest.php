<?php

declare(strict_types=1);

use App\Enums\PartialReturnStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Exceptions\InvoiceNcRequiredException;
use App\Models\Customer;
use App\Models\PartialReturn;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;

beforeEach(function (): void {
    $this->director    = User::factory()->create(['role' => UserRole::Director]);
    $this->zone        = \App\Models\Zone::factory()->create(['distributor_id' => null]);
    $this->seller      = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer    = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->product     = Product::factory()->create([
        'units_per_box'       => 5,
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
    ]);
    $this->paymentTerms = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);

    $this->sale = Sale::factory()->create([
        'status'          => SaleStatus::Delivered,
        'seller_id'       => $this->seller->id,
        'customer_id'     => $this->customer->id,
        'zone_id'         => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'        => 'USD',
        'total_amount'    => '1500.0000',
        'total_currency'  => 'USD',
    ]);

    $this->saleItem = SaleItem::factory()->create([
        'sale_id'             => $this->sale->id,
        'product_id'          => $this->product->id,
        'quantity_boxes'      => 2,
        'quantity_units'      => 0,
        'unit_price_amount'   => '750.0000',
        'unit_price_currency' => 'USD',
        'subtotal_amount'     => '1500.0000',
    ]);
});

it('seller initiates a partial return creating pending_director_confirmation record', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson("/api/v1/sales/{$this->sale->id}/returns", [
            'sale_item_id'   => $this->saleItem->id,
            'quantity_boxes' => 1,
            'quantity_units' => 0,
            'reason'         => 'Client returned one box — damaged packaging.',
        ]);

    $response->assertStatus(201);
    $data = $response->json('data');

    expect($data['status'])->toBe(PartialReturnStatus::PendingDirectorConfirmation->value);
    expect($data['quantity_boxes'])->toBe(1);
    expect($data['refund_amount'])->toBe('750.0000');
    expect($data['refund_currency'])->toBe('USD');
});

it('director confirms partial return without invoice and it is applied', function (): void {
    $return = PartialReturn::factory()->create([
        'sale_id'        => $this->sale->id,
        'sale_item_id'   => $this->saleItem->id,
        'initiated_by'   => $this->seller->id,
        'status'         => PartialReturnStatus::PendingDirectorConfirmation,
        'quantity_boxes' => 1,
        'quantity_units' => 0,
        'refund_amount'  => '750.0000',
        'refund_currency' => 'USD',
        'initiated_at'   => now(),
    ]);

    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/sales/{$this->sale->id}/returns/{$return->id}/confirm");

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe(PartialReturnStatus::Applied->value);
    expect($return->fresh()->confirmed_by)->toBe($this->director->id);
});

it('confirming return on sale with invoice throws InvoiceNcRequiredException', function (): void {
    $return = PartialReturn::factory()->create([
        'sale_id'        => $this->sale->id,
        'sale_item_id'   => $this->saleItem->id,
        'initiated_by'   => $this->seller->id,
        'status'         => PartialReturnStatus::PendingDirectorConfirmation,
        'quantity_boxes' => 1,
        'quantity_units' => 0,
        'refund_amount'  => '750.0000',
        'refund_currency' => 'USD',
        'initiated_at'   => now(),
    ]);

    // Simulate an invoice on the sale
    $saleWithInvoice = new class ($this->sale->getAttributes()) extends Sale {
        public function hasInvoice(): bool { return true; }
        public function lockForUpdate(): static { return $this; }
        public function refresh(): static { return $this; }
    };
    $saleWithInvoice->exists = true;
    $return->setRelation('sale', $saleWithInvoice);

    $action = app(\App\Actions\Sales\ConfirmPartialReturnAction::class);

    expect(fn () => $action->execute($return, $this->director))
        ->toThrow(InvoiceNcRequiredException::class);

    // Status should be awaiting_credit_note after the exception
    expect($return->fresh()->status)->toBe(PartialReturnStatus::AwaitingCreditNote);
});

it('cannot return more boxes than sold', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->postJson("/api/v1/sales/{$this->sale->id}/returns", [
            'sale_item_id'   => $this->saleItem->id,
            'quantity_boxes' => 99,  // sale item only has 2
            'quantity_units' => 0,
            'reason'         => 'Over-return attempt.',
        ]);

    $response->assertStatus(500); // InvalidArgumentException from the action
    // In production, register this as a 422 handler — but for Phase 5 we verify the guard logic fires
});
