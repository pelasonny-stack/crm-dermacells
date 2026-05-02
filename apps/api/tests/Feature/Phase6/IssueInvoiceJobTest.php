<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\Xubio\IssueXubioInvoiceJob;
use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;

/**
 * Verifies that IssueXubioInvoiceJob, when Xubio responds 200, persists
 * the CAE + xubio_id and transitions the row to 'success'.
 *
 * The XubioClient runs in MOCK MODE (XUBIO_CLIENT_ID empty) so no real
 * HTTP fakes are required — the deterministic mock returns a CAE.
 */
beforeEach(function (): void {
    config()->set('services.xubio.client_id', '');

    $this->seller       = User::factory()->create(['role' => \App\Enums\UserRole::Seller]);
    $this->zone         = Zone::factory()->create(['distributor_id' => null]);
    $this->customer     = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = \App\Models\PaymentTerm::factory()->create(['days_to_due' => 0]);
    $this->billing      = CustomerBillingEntity::factory()->create([
        'customer_id' => $this->customer->id,
    ]);
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

it('emits an invoice and persists CAE + xubio_id on Xubio 200', function (): void {
    $invoice = Invoice::create([
        'sale_id'           => $this->sale->id,
        'billing_entity_id' => $this->billing->id,
        'external_ref'      => 'sale-' . $this->sale->id . '-' . \Illuminate\Support\Str::ulid(),
        'voucher_type'      => 'B',
        'status'            => InvoiceStatus::Pending,
    ]);

    (new IssueXubioInvoiceJob($invoice->id))
        ->handle(
            app(\App\Services\Xubio\XubioClient::class),
            app(\App\Services\Xubio\XubioClienteResolver::class),
        );

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Success);
    expect($invoice->cae)->not->toBeNull()->not->toBe('');
    expect($invoice->xubio_id)->not->toBeNull()->not->toBe('');
    expect($invoice->invoice_number)->not->toBeNull();
    expect($invoice->issued_at)->not->toBeNull();
});
