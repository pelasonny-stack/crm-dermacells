<?php

declare(strict_types=1);

use App\Enums\SaleStatus;
use App\Events\Dashboards\AlertCreated;
use App\Events\Dashboards\PaymentRecorded;
use App\Events\Dashboards\SaleDelivered;
use App\Models\Alert;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\Event;


/**
 * Phase 14 — Real-time broadcast event tests.
 *
 * Validates:
 *   - SaleDelivered is dispatched when a Sale transitions to 'delivered'.
 *   - PaymentRecorded is dispatched when a new Payment is created.
 *   - AlertCreated is dispatched when a new Alert is persisted.
 *   - Each event broadcasts on the expected private channels.
 */
describe('Real-time broadcast events', function (): void {
    it('dispatches SaleDelivered event when sale status changes to delivered', function (): void {
        Event::fake([SaleDelivered::class]);

        $sale = Sale::factory()->create(['status' => SaleStatus::Confirmed]);
        $sale->status = SaleStatus::Delivered;
        $sale->save();

        Event::assertDispatched(SaleDelivered::class, function (SaleDelivered $event) use ($sale) {
            return $event->saleId === (string) $sale->getKey();
        });
    });

    it('does not dispatch SaleDelivered for non-status changes', function (): void {
        Event::fake([SaleDelivered::class]);

        $sale = Sale::factory()->create(['status' => SaleStatus::Confirmed]);
        // Update a non-status field — touch() updates timestamps only.
        $sale->touch();

        Event::assertNotDispatched(SaleDelivered::class);
    });

    it('dispatches PaymentRecorded event when a non-reversed payment is created', function (): void {
        Event::fake([PaymentRecorded::class]);

        $payment = Payment::factory()->create(['reversed' => false]);

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $event) use ($payment) {
            return $event->paymentId === (string) $payment->getKey();
        });
    });

    it('does not dispatch PaymentRecorded for reversed payments', function (): void {
        Event::fake([PaymentRecorded::class]);

        Payment::factory()->create(['reversed' => true]);

        Event::assertNotDispatched(PaymentRecorded::class);
    });

    it('dispatches AlertCreated event when a new Alert is created', function (): void {
        Event::fake([AlertCreated::class]);

        $alert = Alert::factory()->create();

        Event::assertDispatched(AlertCreated::class, function (AlertCreated $event) use ($alert) {
            return $event->alertId === (string) $alert->getKey();
        });
    });

    it('SaleDelivered broadcasts on seller and director channels', function (): void {
        $sale = Sale::factory()->make([
            'status'    => 'delivered',
            'seller_id' => '00000000-0000-0000-0000-000000000001',
            'zone_id'   => null,
        ]);

        // Force seller_id + no distributor (direct zone).
        $event = new SaleDelivered($sale);
        $channels = array_map(
            fn ($ch) => $ch->name,
            $event->broadcastOn()
        );

        expect($channels)->toContain('private-user.00000000-0000-0000-0000-000000000001');
        expect($channels)->toContain('private-director');
    });

    it('AlertCreated broadcasts only to target user channel', function (): void {
        $alert = Alert::factory()->make([
            'target_user_id' => '00000000-0000-0000-0000-000000000002',
        ]);

        $event    = new AlertCreated($alert);
        $channels = array_map(fn ($ch) => $ch->name, $event->broadcastOn());

        expect($channels)->toHaveCount(1);
        expect($channels[0])->toBe('private-user.00000000-0000-0000-0000-000000000002');
    });
});
