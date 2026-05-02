<?php

declare(strict_types=1);

namespace App\Events\Dashboards;

use App\Models\Alert;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reverb broadcast event fired when a new Alert is persisted (§14, §15).
 *
 * Broadcasts only to the target user's private channel:
 *   private-user.{target_user_id}
 *
 * This allows the dashboard "today alerts" section to refresh in real-time
 * without the user having to manually reload.
 */
final class AlertCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $alertId;
    public readonly string $targetUserId;
    public readonly string $alertType;
    public readonly string $severity;

    public function __construct(Alert $alert)
    {
        $this->alertId      = (string) $alert->getKey();
        $this->targetUserId = (string) $alert->getAttribute('target_user_id');
        $this->alertType    = (string) $alert->getAttribute('alert_type');
        $this->severity     = (string) $alert->getAttribute('severity');
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->targetUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'alert.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'alert_id'   => $this->alertId,
            'alert_type' => $this->alertType,
            'severity'   => $this->severity,
        ];
    }
}
