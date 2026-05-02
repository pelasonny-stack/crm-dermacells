<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push notification dispatched when a stock row falls below its configured
 * minimum (§8.3, §15).
 *
 * Channels:
 *   - database  (persists to `notifications` table for in-app inbox)
 *   - broadcast (Reverb — real-time push to web PWA)
 *   - fcm       (Firebase Cloud Messaging — mobile push via kreait/laravel-firebase)
 *
 * FCM channel is a placeholder until kreait/laravel-firebase is fully wired
 * (Phase 15). The database + broadcast channels are functional in Phase 4.
 */
class StockBelowMinimumNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $stockType,   // 'central' | 'distributor' | 'seller'
        public readonly string $productId,
        public readonly string $productName,
        public readonly int $available,
        public readonly int $minimum,
        public readonly ?string $entityId,
        public readonly string $entityName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toBroadcast(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'stock_below_minimum',
            'stock_type'   => $this->stockType,
            'product_id'   => $this->productId,
            'product_name' => $this->productName,
            'available'    => $this->available,
            'minimum'      => $this->minimum,
            'entity_id'    => $this->entityId,
            'entity_name'  => $this->entityName,
            'title'        => "Stock bajo mínimo: {$this->productName}",
            'body'         => sprintf(
                '%s tiene %d cajas disponibles (mínimo: %d).',
                $this->entityName,
                $this->available,
                $this->minimum,
            ),
            'occurred_at'  => now()->toIso8601String(),
        ];
    }
}
