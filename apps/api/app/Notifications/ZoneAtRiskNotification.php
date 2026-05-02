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
 * ZoneAtRiskNotification — Phase 10 (§10.3: "Zona en riesgo").
 *
 * Sent to the Distributor of the zone + all Directors when the percentage of
 * inactive customers in a zone exceeds the configured threshold.
 */
class ZoneAtRiskNotification extends Notification implements ShouldBroadcast
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
            'alert_type'     => 'zone_at_risk',
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
        return 'alert.zone_at_risk';
    }
}
