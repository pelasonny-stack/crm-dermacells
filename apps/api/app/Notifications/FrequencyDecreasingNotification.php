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
 * FrequencyDecreasingNotification — Phase 10 (§10.3: "Frecuencia decreciente").
 *
 * Sent to the Seller when a customer's purchase interval exceeds the average by > 20%.
 */
class FrequencyDecreasingNotification extends Notification implements ShouldBroadcast
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
            'alert_type'     => 'frequency_decreasing',
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
        return 'alert.frequency_decreasing';
    }
}
