<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * FirstPurchaseNoReorderNotification — Phase 10 (§10.3: "Primera compra sin recompra").
 *
 * Sent to the Seller when X days have elapsed since a customer's first purchase
 * and they have not made a second purchase.
 */
class FirstPurchaseNoReorderNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        private readonly ?Model $reference,
        private readonly array $payload,
        private readonly Alert $alert,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'alert_id'       => $this->alert->id,
            'alert_type'     => 'first_purchase_no_reorder',
            'severity'       => $this->alert->severity,
            'reference_type' => $this->alert->reference_entity_type,
            'reference_id'   => $this->alert->reference_entity_id,
            'payload'        => $this->payload,
        ];
    }

    /** @return array<string, mixed> */
    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . $this->alert->target_user_id);
    }

    public function broadcastType(): string
    {
        return 'alert.first_purchase_no_reorder';
    }
}
