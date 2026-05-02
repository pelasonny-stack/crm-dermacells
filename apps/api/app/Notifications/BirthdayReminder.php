<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CustomerContact;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Birthday reminder push sent to the assigned Seller at 8:00 AM ART (§3.6, §15).
 *
 * CHANNEL: database (persisted in `notifications` table) + FCM push via
 * kreait/laravel-firebase (Phase 12 will wire up the FCM channel once Firebase
 * credentials are in Secrets Manager). For now the notification is stored in
 * the database notifications table so it surfaces in the Filament/API panel.
 *
 * The `customers:dispatch-birthday-alerts` Artisan command queries contacts
 * where EXTRACT(MONTH FROM birthday) = today's month AND
 * EXTRACT(DAY FROM birthday) = today's day, then sends one BirthdayReminder
 * per contact to the customer's assigned Seller.
 *
 * Each notification is idempotent for the calendar day: the command checks
 * whether a BirthdayReminder for the same contact was already sent today
 * before dispatching (prevents double-fire on scheduler restart).
 */
class BirthdayReminder extends Notification
{
    public function __construct(
        private readonly CustomerContact $contact,
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
            'type'         => 'birthday_reminder',
            'customer_id'  => $this->contact->customer_id,
            'contact_id'   => $this->contact->id,
            'contact_name' => $this->contact->full_name,
            'birthday'     => $this->contact->birthday?->toDateString(),
            'message'      => "Hoy es el cumpleaños de {$this->contact->full_name}.",
        ]);
    }

    /**
     * Unique ID for deduplication — one notification per contact per day.
     * Used by the dispatch command to skip already-sent notifications.
     */
    public function uniqueId(): string
    {
        return "birthday-{$this->contact->id}-" . now()->timezone('America/Argentina/Buenos_Aires')->toDateString();
    }
}
