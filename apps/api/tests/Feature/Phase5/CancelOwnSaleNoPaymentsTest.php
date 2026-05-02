<?php

declare(strict_types=1);

use App\Actions\Stock\RollbackStockOnCancelAction;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesStatusHistory;
use App\Models\User;

beforeEach(function (): void {
    $this->zone         = \App\Models\Zone::factory()->create(['distributor_id' => null]);
    $this->seller       = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer     = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);

    // Draft sale owned by this seller, no payments
    $this->sale = Sale::factory()->create([
        'status'          => SaleStatus::Draft,
        'seller_id'       => $this->seller->id,
        'customer_id'     => $this->customer->id,
        'zone_id'         => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'        => 'USD',
        'total_amount'    => '750.0000',
        'total_currency'  => 'USD',
    ]);
});

it('seller can cancel their own draft sale with no payments', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->deleteJson("/api/v1/sales/{$this->sale->id}", [
            'reason' => 'Client changed their mind about the order.',
        ]);

    $response->assertStatus(204);

    $this->sale->refresh();
    expect($this->sale->status)->toBe(SaleStatus::Cancelled);
    expect($this->sale->cancellation_reason)->toBe('Client changed their mind about the order.');
    expect($this->sale->cancelled_by)->toBe($this->seller->id);
    expect($this->sale->cancelled_at)->not->toBeNull();

    // Status history recorded
    $history = SalesStatusHistory::where('sale_id', $this->sale->id)
        ->where('to_status', 'cancelled')
        ->first();
    expect($history)->not->toBeNull();
});

it('seller can cancel their own confirmed sale with no payments', function (): void {
    $this->sale->update(['status' => SaleStatus::Confirmed]);

    // Mock rollback stock since confirmed sales need stock rollback
    $this->mock(RollbackStockOnCancelAction::class, fn ($m) => $m->shouldReceive('execute')->zeroOrMoreTimes());

    $response = $this->actingAs($this->seller, 'sanctum')
        ->deleteJson("/api/v1/sales/{$this->sale->id}", [
            'reason' => 'Out of stock agreement.',
        ]);

    $response->assertStatus(204);
    expect($this->sale->fresh()->status)->toBe(SaleStatus::Cancelled);
});

it('requires a non-empty reason to cancel', function (): void {
    $response = $this->actingAs($this->seller, 'sanctum')
        ->deleteJson("/api/v1/sales/{$this->sale->id}", ['reason' => 'ab']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['reason']);
});
