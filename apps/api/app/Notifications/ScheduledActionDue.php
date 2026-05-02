<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ScheduledAction;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification fired when a scheduled action's date arrives (§3.8, §15).
 *
 * Sent to the assigned Seller of the customer who owns the action.
 * The `customers:dispatch-birthday-alerts` command also handles this dispatch —
 * both birthday and scheduled-action notifications share the same daily 7:55 AM
 * ART run window.
 *
 * CHANNEL: database + FCM (Phase 12). See BirthdayReminder for same rationale.
 */
class ScheduledActionDue extends Notification
{
    public function __construct(
        private readonly ScheduledAction $action,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        // TODO Phase 12: add 'firebase' (FCM) channel once kreait is configured.
        return ['database'];
    }

    public function toDatabase(mixed $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type'             => 'scheduled_action_due',
            'customer_id'      => $this->action->customer_id,
            'scheduled_action_id' => $this->action->id,
            'scheduled_date'   => $this->action->scheduled_date->toDateString(),
            'note'             => $this->action->note,
            'message'          => "Acción programada vencida: {$this->action->note}",
        ]);
    }

    /**
     * Unique ID for deduplication — one notification per action per day.
     */
    public function uniqueId(): string
    {
        return "scheduled-action-{$this->action->id}-" . now()->timezone('America/Argentina/Buenos_Aires')->toDateString();
    }
}
