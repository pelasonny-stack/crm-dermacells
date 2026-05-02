<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push notification dispatched when a stock lot is approaching its expiry date
 * within the configured alert window (§8.3, §16.11).
 *
 * Channels: database + broadcast (Reverb). FCM placeholder for Phase 15.
 */
class LotExpiringSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $lotId,
        public readonly string $lotNumber,
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $expiryDate,       // Y-m-d
        public readonly int $daysUntilExpiry,
        public readonly int $quantityBoxes,
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
            'type'              => 'lot_expiring_soon',
            'lot_id'            => $this->lotId,
            'lot_number'        => $this->lotNumber,
            'product_id'        => $this->productId,
            'product_name'      => $this->productName,
            'expiry_date'       => $this->expiryDate,
            'days_until_expiry' => $this->daysUntilExpiry,
            'quantity_boxes'    => $this->quantityBoxes,
            'title'             => "Lote próximo a vencer: {$this->lotNumber}",
            'body'              => sprintf(
                '%s — Lote %s vence el %s (%d días). %d cajas en stock.',
                $this->productName,
                $this->lotNumber,
                $this->expiryDate,
                $this->daysUntilExpiry,
                $this->quantityBoxes,
            ),
            'occurred_at'       => now()->toIso8601String(),
        ];
    }
}
