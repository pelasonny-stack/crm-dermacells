<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Jobs\Xubio\ReconcileXubioInvoiceJob;
use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\InvoiceRequiresDirectorAction;
use App\Services\Xubio\XubioClient;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('services.xubio.client_id', '');

    $seller       = User::factory()->create(['role' => UserRole::Seller]);
    $this->director = User::factory()->create(['role' => UserRole::Director, 'is_active' => true]);
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

it('marks invoice failed_manual_review and notifies Director when reconcile finds nothing', function (): void {
    Notification::fake();

    $invoice = Invoice::factory()->reconciling()->create([
        'sale_id'           => $this->sale->id,
        'billing_entity_id' => $this->billing->id,
        'voucher_type'      => 'B',
    ]);

    $client = Mockery::mock(XubioClient::class);
    $client->shouldReceive('findInvoiceByExternalRef')
        ->with($invoice->external_ref)
        ->andReturn(null);

    (new ReconcileXubioInvoiceJob($invoice->id))->handle($client);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::FailedManualReview);

    Notification::assertSentTo($this->director, InvoiceRequiresDirectorAction::class);
});

afterEach(function (): void {
    Mockery::close();
});
