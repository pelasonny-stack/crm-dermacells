<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuthorizationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification dispatched to the original requester (Seller or Distributor)
 * when a Director approves or rejects their authorization request (§13.2,
 * §15 — "Solicitud resuelta" alert).
 *
 * Channels:
 * - database: persists for the requester's notification history.
 * - broadcast: sends via Reverb to `private-user.{id}` so the requester's
 *   UI unblocks the pending operation immediately.
 *
 * Implements ShouldQueue — resolution notifications are low-latency enough
 * to queue rather than block the Director's PATCH /resolve response.
 */
final class AuthorizationResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AuthorizationRequest $authorizationRequest,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'authorization_request_id' => $this->authorizationRequest->id,
            'type'                     => $this->authorizationRequest->type->value,
            'type_label'               => $this->authorizationRequest->type->label(),
            'status'                   => $this->authorizationRequest->status,
            'resolved_by'              => $this->authorizationRequest->resolved_by,
            'resolved_at'              => $this->authorizationRequest->resolved_at?->toIso8601String(),
            'rejection_reason'         => $this->authorizationRequest->rejection_reason,
            'sale_id'                  => $this->authorizationRequest->sale_id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
