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
 * ScheduledActionDueNotification — Phase 10 extension of Phase 3 (§3.8).
 *
 * Sent to the Seller when the date of a scheduled_actions row arrives and the
 * action is still unresolved. Routed through AlertDispatcher like all other
 * Phase 10 alert types.
 */
class ScheduledActionDueNotification extends Notification implements ShouldBroadcast
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
            'alert_type'     => 'scheduled_action_due',
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
        return 'alert.scheduled_action_due';
    }
}
