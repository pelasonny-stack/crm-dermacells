<?php

declare(strict_types=1);

namespace App\Events\Dashboards;

use App\Models\Sale;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Reverb broadcast event fired when a Sale transitions to 'delivered' (§14).
 *
 * Broadcasts on:
 *   private-user.{seller_id}              — Vendedor's own dashboard refresh trigger.
 *   private-distributor.{distributor_id}  — Zone distributor's dashboard.
 *   private-director                      — Director global pulse update.
 *
 * Payload is minimal — clients re-fetch the dashboard endpoint on receipt.
 * Heavy data is never included in broadcast payloads (avoids Reverb
 * message-size limits and keeps the payload version-stable).
 */
final class SaleDelivered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $saleId;
    public readonly string $sellerId;
    public readonly ?string $distributorId;
    public readonly string $amount;
    public readonly string $currency;

    public function __construct(Sale $sale)
    {
        $this->saleId    = (string) $sale->getKey();
        $this->sellerId  = (string) $sale->getAttribute('seller_id');
        // total_amount is the raw numeric column; total is the MoneyCast column.
        $this->amount    = (string) $sale->getRawOriginal('total_amount', $sale->getAttribute('total_amount') ?? '0');
        $this->currency  = (string) $sale->getAttribute('currency');

        // Resolve the zone's distributor (may be null for direct zones).
        $this->distributorId = $sale->getAttribute('zone_id')
            ? $this->resolveDistributor((string) $sale->getAttribute('zone_id'))
            : null;
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->sellerId),
            new PrivateChannel('director'),
        ];

        if ($this->distributorId !== null) {
            $channels[] = new PrivateChannel('distributor.' . $this->distributorId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'sale.delivered';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
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
