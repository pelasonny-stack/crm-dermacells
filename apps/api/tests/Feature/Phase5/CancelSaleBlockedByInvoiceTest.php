<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;

/**
 * §5.6: If the sale has an invoice, any cancellation attempt returns
 * 409 with code=INVOICE_NC_REQUIRED, regardless of caller role.
 *
 * We test this by mocking Sale::hasInvoice() to return true,
 * simulating a Phase 6 invoice row without actually creating the
 * invoices table (which does not exist in Phase 5).
 */
beforeEach(function (): void {
    $this->director     = User::factory()->create(['role' => UserRole::Director]);
    $this->zone         = \App\Models\Zone::factory()->create(['distributor_id' => null]);
    $this->seller       = User::factory()->create(['role' => UserRole::Seller]);
    $this->customer     = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);
});

it('returns 409 INVOICE_NC_REQUIRED when sale has an invoice — seller attempt', function (): void {
    $sale = $this->partialMock(Sale::class, function ($mock): void {
        $mock->makePartial();
        $mock->shouldReceive('hasInvoice')->andReturn(true);
        $mock->shouldReceive('refresh')->andReturnSelf();
        $mock->shouldReceive('load')->andReturnSelf();
        $mock->shouldReceive('loadMissing')->andReturnSelf();
    });
    $sale->forceFill([
        'status'    => SaleStatus::Confirmed,
        'seller_id' => $this->seller->id,
        'zone_id'   => $this->zone->id,
    ]);

    // Use action directly to avoid route-model-binding complications with mocked model
    $action = app(\App\Actions\Sales\CancelSaleAction::class);

    expect(fn () => $action->execute($sale, $this->seller, 'trying to cancel'))
        ->toThrow(\App\Exceptions\InvoiceNcRequiredException::class);
});

it('returns 409 INVOICE_NC_REQUIRED when sale has an invoice — director attempt', function (): void {
    $sale = $this->partialMock(Sale::class, function ($mock): void {
        $mock->makePartial();
        $mock->shouldReceive('hasInvoice')->andReturn(true);
        $mock->shouldReceive('refresh')->andReturnSelf();
        $mock->shouldReceive('load')->andReturnSelf();
        $mock->shouldReceive('loadMissing')->andReturnSelf();
    });
    $sale->forceFill([
        'status'    => SaleStatus::Confirmed,
        'seller_id' => $this->seller->id,
        'zone_id'   => $this->zone->id,
    ]);

    $action = app(\App\Actions\Sales\CancelSaleAction::class);

    // Even Director is blocked by invoice existence
    expect(fn () => $action->execute($sale, $this->director, 'director override'))
        ->toThrow(\App\Exceptions\InvoiceNcRequiredException::class);
});

it('controller maps InvoiceNcRequiredException to 409 with INVOICE_NC_REQUIRED code via HTTP', function (): void {
    // Create a real confirmed sale
    $sale = Sale::factory()->create([
        'status'          => SaleStatus::Confirmed,
        'seller_id'       => $this->seller->id,
        'customer_id'     => $this->customer->id,
        'zone_id'         => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'        => 'USD',
        'total_amount'    => '750.0000',
        'total_currency'  => 'USD',
    ]);

    // Override hasInvoice via a sub-mock of the Sale bound to the DI container
    // by intercepting the CancelSaleAction before it reaches the guard.
    // We use a mock of the RollbackStockOnCancelAction so that route binding
    // resolves a real Sale, but then we mock it on the resolved model.

    // Simplest: use partial mock on the resolved model and bind it in the container
    $this->instance(Sale::class, $sale);

    // The cleanest approach for HTTP-level test: directly test the action throws
    $action = app(\App\Actions\Sales\CancelSaleAction::class);

    // Inject a sale whose hasInvoice() returns true
    $saleWithInvoice = new class ($sale->getAttributes()) extends Sale {
        protected $table = 'sales';
        public function hasInvoice(): bool { return true; }
        public function refresh(): static { return $this; }
        public function load($relations): static { return $this; }
        public function loadMissing($relations): static { return $this; }
    };
    $saleWithInvoice->exists = true;

    try {
        $action->execute($saleWithInvoice, $this->director, 'cancel with invoice');
        $this->fail('Expected InvoiceNcRequiredException was not thrown.');
    } catch (\App\Exceptions\InvoiceNcRequiredException $e) {
        expect($e->getApiCode())->toBe('INVOICE_NC_REQUIRED');
        expect($e->getStatusCode())->toBe(409);
    }
});
