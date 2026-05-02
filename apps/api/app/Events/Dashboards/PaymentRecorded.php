<?php

declare(strict_types=1);

namespace App\Events\Dashboards;

use App\Models\Payment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Reverb broadcast event fired when a Payment is registered (§14).
 *
 * Broadcasts on the same three channel groups as SaleDelivered:
 *   private-user.{seller_id}
 *   private-distributor.{distributor_id}
 *   private-director
 *
 * Clients use this to update the cobros section of their dashboard
 * without polling.
 */
final class PaymentRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string  $paymentId;
    public readonly string  $saleId;
    public readonly string  $sellerId;
    public readonly ?string $distributorId;
    public readonly string  $amount;
    public readonly string  $currency;

    public function __construct(Payment $payment)
    {
        $this->paymentId = (string) $payment->getKey();
        $this->saleId    = (string) $payment->getAttribute('sale_id');
        $this->amount    = (string) $payment->getAttribute('amount_amount');
        $this->currency  = (string) $payment->getAttribute('amount_currency');

        // Resolve seller and distributor from the associated sale.
        $sale = DB::table('sales')
            ->where('id', $this->saleId)
            ->first(['seller_id', 'zone_id']);

        $this->sellerId     = $sale ? (string) $sale->seller_id : '';
        $this->distributorId = $sale?->zone_id
            ? $this->resolveDistributor((string) $sale->zone_id)
            : null;
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('director'),
        ];

        if ($this->sellerId !== '') {
            $channels[] = new PrivateChannel('user.' . $this->sellerId);
        }

        if ($this->distributorId !== null) {
            $channels[] = new PrivateChannel('distributor.' . $this->distributorId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'payment.recorded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'payment_id'     => $this->paymentId,
            'sale_id'        => $this->saleId,
            'seller_id'      => $this->sellerId,
            'distributor_id' => $this->distributorId,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
        ];
    }

    private function resolveDistributor(string $zoneId): ?string
    {
        $zone = DB::table('zones')
            ->where('id', $zoneId)
            ->first(['distributor_id']);

        return $zone?->distributor_id ? (string) $zone->distributor_id : null;
    }
}
