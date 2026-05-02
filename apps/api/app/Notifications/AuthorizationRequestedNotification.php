<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AuthorizationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification dispatched to all active Directors when a new authorization
 * request is created (§13.2, §15 — "Solicitud de autorización" alert).
 *
 * Channels:
 * - database: persists in the `notifications` table for the Director's
 *   notification bell / history.
 * - broadcast: sends via Reverb to the Director's private channel so the
 *   in-app bell updates in real time (complements the AuthorizationRequested
 *   event which targets `private-director` for the queue widget).
 *
 * Implements ShouldQueue so the Director fan-out (potentially many recipients)
 * runs asynchronously and does not block the HTTP response to the requester.
 */
final class AuthorizationRequestedNotification extends Notification implements ShouldQueue
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
            'requested_by'             => $this->authorizationRequest->requested_by,
            'current_value'            => $this->authorizationRequest->current_value,
            'proposed_value'           => $this->authorizationRequest->proposed_value,
            'value_currency'           => $this->authorizationRequest->value_currency,
            'reason'                   => $this->authorizationRequest->reason,
            'sale_id'                  => $this->authorizationRequest->sale_id,
            'created_at'               => $this->authorizationRequest->created_at?->toIso8601String(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
