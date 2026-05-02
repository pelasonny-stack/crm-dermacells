<?php

declare(strict_types=1);

use App\Enums\PartialReturnStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Events\PartialReturnAwaitingCreditNote;
use App\Jobs\Xubio\IssueXubioCreditNoteJob;
use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use App\Models\PartialReturn;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\Bus;

/**
 * When a PartialReturn transitions to AwaitingCreditNote, the
 * PartialReturnBillingObserver fires PartialReturnAwaitingCreditNote, and
 * OnPartialReturnAwaitingCreditNote dispatches IssueXubioCreditNoteJob.
 */
beforeEach(function (): void {
    config()->set('services.xubio.client_id', '');

    $this->director = User::factory()->create(['role' => UserRole::Director]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller]);
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->customer = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $this->zone->id,
    ]);
    $this->paymentTerms = PaymentTerm::factory()->create(['days_to_due' => 0]);
    $this->product = Product::factory()->create([
        'units_per_box'       => 5,
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
    ]);
    $this->billing = CustomerBillingEntity::factory()->create(['customer_id' => $this->customer->id]);
    $this->sale = Sale::factory()->create([
        'status'           => SaleStatus::Delivered,
        'customer_id'      => $this->customer->id,
        'seller_id'        => $this->seller->id,
        'zone_id'          => $this->zone->id,
        'payment_terms_id' => $this->paymentTerms->id,
        'currency'         => 'USD',
        'total_amount'     => '1500.0000',
        'total_currency'   => 'USD',
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

it('dispatches IssueXubioCreditNoteJob when partial return enters awaiting_credit_note', function (): void {
    Bus::fake();

    $return = PartialReturn::factory()->create([
        'sale_id'         => $this->sale->id,
        'sale_item_id'    => $this->saleItem->id,
        'initiated_by'    => $this->seller->id,
        'status'          => PartialReturnStatus::PendingDirectorConfirmation,
        'quantity_boxes'  => 1,
        'refund_amount'   => '750.0000',
        'refund_currency' => 'USD',
        'initiated_at'    => now(),
    ]);

    // Trigger the observer-fired transition
    $return->update([
        'status'       => PartialReturnStatus::AwaitingCreditNote,
        'confirmed_by' => $this->director->id,
        'confirmed_at' => now(),
    ]);

    Bus::assertDispatched(IssueXubioCreditNoteJob::class, function (IssueXubioCreditNoteJob $job) use ($return) {
        return $job->partialReturnId === $return->id;
    });
});

it('does not dispatch the job when the status changes to a non-awaiting state', function (): void {
    Bus::fake();

    $return = PartialReturn::factory()->create([
        'sale_id'         => $this->sale->id,
        'sale_item_id'    => $this->saleItem->id,
        'initiated_by'    => $this->seller->id,
        'status'          => PartialReturnStatus::PendingDirectorConfirmation,
        'quantity_boxes'  => 1,
        'refund_amount'   => '750.0000',
        'refund_currency' => 'USD',
        'initiated_at'    => now(),
    ]);

    $return->update([
        'status'       => PartialReturnStatus::Applied,
        'confirmed_by' => $this->director->id,
        'confirmed_at' => now(),
    ]);

    Bus::assertNotDispatched(IssueXubioCreditNoteJob::class);
});
