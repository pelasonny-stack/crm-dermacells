<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\Xubio\IssueXubioInvoiceJob;
use App\Jobs\Xubio\ReconcileXubioInvoiceJob;
use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use App\Services\Xubio\Exceptions\XubioTimeoutException;
use App\Services\Xubio\XubioClient;
use App\Services\Xubio\XubioClienteResolver;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config()->set('services.xubio.client_id', '');

    Bus::fake();

    $seller       = User::factory()->create(['role' => \App\Enums\UserRole::Seller]);
    $zone         = Zone::factory()->create(['distributor_id' => null]);
    $customer     = Customer::factory()->create([
        'assigned_seller_id' => $seller->id,
        'zone_id'            => $zone->id,
    ]);
    $paymentTerms = PaymentTerm::factory()->create(['days_to_due' => 0]);
    $this->billing = CustomerBillingEntity::factory()->create(['customer_id' => $customer->id]);
    $this->sale = Sale::factory()->create([
        'customer_id'      => $customer->id,
        'seller_id'        => $seller->id,
        'zone_id'          => $zone->id,
        'payment_terms_id' => $paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '750.0000',
        'total_currency'   => 'USD',
    ]);
});

it('on timeout marks invoice reconciling and dispatches ReconcileXubioInvoiceJob', function (): void {
    $invoice = Invoice::factory()->create([
        'sale_id'           => $this->sale->id,
        'billing_entity_id' => $this->billing->id,
        'voucher_type'      => 'B',
        'status'            => InvoiceStatus::Pending,
    ]);

    // Mock XubioClient to throw timeout on emitInvoice
    $client = Mockery::mock(XubioClient::class);
    $client->shouldReceive('emitInvoice')
        ->once()
        ->andThrow(new XubioTimeoutException('simulated timeout'));

    $resolver = Mockery::mock(XubioClienteResolver::class);
    $resolver->shouldReceive('resolve')->andReturn('cliente-mock-id');

    (new IssueXubioInvoiceJob($invoice->id))->handle($client, $resolver);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Reconciling);

    Bus::assertDispatched(ReconcileXubioInvoiceJob::class, function (ReconcileXubioInvoiceJob $job) use ($invoice) {
        return $job->invoiceId === $invoice->id;
    });
});

it('reconcile job links xubio_id when find by external_ref hits', function (): void {
    $invoice = Invoice::factory()->reconciling()->create([
        'sale_id'           => $this->sale->id,
        'billing_entity_id' => $this->billing->id,
        'voucher_type'      => 'B',
    ]);

    $client = Mockery::mock(XubioClient::class);
    $client->shouldReceive('findInvoiceByExternalRef')
        ->with($invoice->external_ref)
        ->andReturn([
            'id'              => 'xubio-found-id-1',
            'numero'          => '0001-99999999',
            'cae'             => '70000000000001',
            'tipoComprobante' => 'B',
            'total'           => '12345.67',
        ]);

    (new ReconcileXubioInvoiceJob($invoice->id))->handle($client);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Success);
    expect($invoice->xubio_id)->toBe('xubio-found-id-1');
    expect($invoice->cae)->toBe('70000000000001');
});

afterEach(function (): void {
    Mockery::close();
});
