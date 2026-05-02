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
 * Broadcast event fired when a Director approves or rejects an authorization
 * request (§13.2).
 *
 * Delivered to the requester's private channel `private-user.{requested_by}`
 * so only the originating Seller/Distributor receives the resolution update.
 *
 * ShouldBroadcastNow ensures the user sees the result without polling.
 */
final class AuthorizationResolved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AuthorizationRequest $authorizationRequest,
    ) {}

    /**
     * The Reverb private channel scoped to the original requester.
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->authorizationRequest->requested_by),
        ];
    }

    /**
     * Payload includes the resolution outcome and, when rejected, the reason.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id'               => $this->authorizationRequest->id,
            'type'             => $this->authorizationRequest->type->value,
            'status'           => $this->authorizationRequest->status,
            'resolved_by'      => $this->authorizationRequest->resolved_by,
            'resolved_at'      => $this->authorizationRequest->resolved_at?->toIso8601String(),
            'rejection_reason' => $this->authorizationRequest->rejection_reason,
        ];
    }

    public function broadcastAs(): string
    {
        return 'AuthorizationResolved';
    }
}
