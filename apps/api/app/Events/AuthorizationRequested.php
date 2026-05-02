<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AuthorizationRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired when a Seller or Distributor submits a new
 * authorization request (§13.2).
 *
 * Delivered to the `private-director` Reverb channel so every active
 * Director receives an in-app notification immediately.
 *
 * ShouldBroadcastNow bypasses the queue to ensure low latency — the
 * Director's approval queue widget must update in real time.
 */
final class AuthorizationRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AuthorizationRequest $authorizationRequest,
    ) {}

    /**
     * The Reverb channel this event is broadcast on.
     *
     * `private-director` is a single presence channel visible only to users
     * whose role is 'director' (enforced by the channel authorization route
     * in routes/channels.php — to be added in Phase 11 route registration).
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('director')];
    }

    /**
     * Payload pushed to the Reverb channel.
     *
     * Keeps the payload minimal — the client fetches full details via REST.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id'             => $this->authorizationRequest->id,
            'type'           => $this->authorizationRequest->type->value,
            'type_label'     => $this->authorizationRequest->type->label(),
            'requested_by'   => $this->authorizationRequest->requested_by,
            'current_value'  => $this->authorizationRequest->current_value,
            'proposed_value' => $this->authorizationRequest->proposed_value,
            'reason'         => $this->authorizationRequest->reason,
            'created_at'     => $this->authorizationRequest->created_at?->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'AuthorizationRequested';
    }
}
