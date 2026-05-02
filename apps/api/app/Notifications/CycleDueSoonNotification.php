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
 * CycleDueSoonNotification — Phase 10 (§10.3: "Próximo a vencer ciclo").
 *
 * Sent to the Seller when a customer's expected purchase cycle is about to expire.
 *
 * Channels:
 *   - database   : persisted via Laravel's notifications table for /api/v1/alerts/me
 *   - broadcast  : Reverb private channel for real-time dashboard updates
 *
 * The Alert row is already persisted by AlertDispatcher before this notification
 * is instantiated; the notification payload mirrors the Alert for delivery.
 */
class CycleDueSoonNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        private readonly ?Model $reference,
        private readonly array $payload,
        private readonly Alert $alert,
    ) {
    }

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
        return [
            'alert_id'         => $this->alert->id,
            'alert_type'       => 'cycle_due_soon',
            'severity'         => $this->alert->severity,
            'reference_type'   => $this->alert->reference_entity_type,
            'reference_id'     => $this->alert->reference_entity_id,
            'payload'          => $this->payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
        return 'alert.cycle_due_soon';
    }
}
