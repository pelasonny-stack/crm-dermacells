<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Services;

use App\Models\Alert;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * AlertDispatcher — Phase 10 centralised alert fan-out (§10.3, §15).
 *
 * RESPONSIBILITIES
 * ================
 * 1. Idempotency check: prevents re-firing the same (type, target, reference)
 *    within a 24-hour window using a Redis cache key.
 * 2. Persistence: inserts one alerts row per target user.
 * 3. Notification: fires the matching Laravel Notification on database +
 *    broadcast channels (Reverb) per target.
 * 4. FCM hook: placeholder — Phase 15 integrates kreait/laravel-firebase.
 *    The hook is a no-op until Phase 15 wires the FCM channel.
 *
 * IDEMPOTENCY KEY
 * ===============
 * Cache key: "alert_dispatched:{type}:{target_user_id}:{reference_id|null}"
 * TTL: 24 hours.
 *
 * USAGE
 * =====
 *   $dispatcher->dispatch(
 *       type:      'customer_inactive',
 *       targets:   [$seller, $distributor],
 *       reference: $customer,          // Eloquent model
 *       payload:   ['days_inactive' => 72],
 *       severity:  'warning',
 *   );
 *
 * The notification class is resolved by convention from the type string:
 *   'customer_inactive' → App\Notifications\CustomerInactiveNotification
 * If the resolved class does not exist, only the alerts row is persisted and
 * a warning is logged (graceful degradation — the row is still queryable).
 */
class AlertDispatcher
{
    /** Map of alert_type → FQCN of the matching Notification class. */
    private const NOTIFICATION_MAP = [
        'cycle_due_soon'              => \App\Notifications\CycleDueSoonNotification::class,
        'frequency_decreasing'        => \App\Notifications\FrequencyDecreasingNotification::class,
        'customer_inactive'           => \App\Notifications\CustomerInactiveNotification::class,
        'customer_recovered'          => \App\Notifications\CustomerRecoveredNotification::class,
        'first_purchase_no_reorder'   => \App\Notifications\FirstPurchaseNoReorderNotification::class,
        'zone_at_risk'                => \App\Notifications\ZoneAtRiskNotification::class,
        'scheduled_action_due'        => \App\Notifications\ScheduledActionDueNotification::class,
    ];

    /**
     * Dispatch an alert to one or more target users.
     *
     * @param  string              $type      Alert type identifier (snake_case).
     * @param  User|array<User>    $targets   One or more target User models.
     * @param  Model|null          $reference Optional Eloquent model that is the subject.
     * @param  array<string,mixed> $payload   Arbitrary JSON-serialisable payload.
     * @param  string              $severity  'info' | 'warning' | 'critical'.
     * @return Alert[]             The persisted Alert rows (one per target, skipping duplicates).
     */
    public function dispatch(
        string $type,
        User|array $targets,
        ?Model $reference = null,
        array $payload = [],
        string $severity = 'info',
    ): array {
        $targets = is_array($targets) ? $targets : [$targets];
        $persisted = [];

        $referenceType = $reference ? get_class($reference) : null;
        $referenceId   = $reference ? (string) $reference->getKey() : null;

        foreach ($targets as $target) {
            if (! $target instanceof User) {
                Log::warning("AlertDispatcher: skipping non-User target for type={$type}.");
                continue;
            }

            $cacheKey = $this->idempotencyKey($type, (string) $target->getKey(), $referenceId);

            if (Cache::has($cacheKey)) {
                Log::debug("AlertDispatcher: SKIP (idempotency) type={$type} target={$target->getKey()}.");
                continue;
            }

            // Persist the alert row
            $alert = Alert::create([
                'alert_type'           => $type,
                'target_user_id'       => $target->getKey(),
                'reference_entity_type' => $referenceType,
                'reference_entity_id'  => $referenceId,
                'payload_json'         => $payload,
                'severity'             => $severity,
                'delivered'            => false,
                'created_at'           => now(),
            ]);

            // Mark delivered immediately (we're about to fire the notification)
            $alert->markDelivered();

            $persisted[] = $alert;

            // Fire notification if a matching class exists
            $this->fireNotification($type, $target, $reference, $payload, $alert);

            // FCM hook (Phase 15 placeholder)
            $this->triggerFcm($target, $type, $payload);

            // Lock for 24h
            Cache::put($cacheKey, true, now()->addHours(24));

            Log::info("AlertDispatcher: dispatched type={$type} to user={$target->getKey()}.");
        }

        return $persisted;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function idempotencyKey(string $type, string $targetId, ?string $referenceId): string
    {
        return 'alert_dispatched:' . implode(':', array_filter([$type, $targetId, $referenceId]));
    }

    private function fireNotification(
        string $type,
        User $target,
        ?Model $reference,
        array $payload,
        Alert $alert,
    ): void {
        $notificationClass = self::NOTIFICATION_MAP[$type] ?? null;

        if ($notificationClass === null || ! class_exists($notificationClass)) {
            Log::warning("AlertDispatcher: no notification class for type={$type}.");

            return;
        }

        try {
            $notification = new $notificationClass($reference, $payload, $alert);
            Notification::send($target, $notification);
        } catch (\Throwable $e) {
            // Notification delivery failure should not break alert persistence.
            Log::error("AlertDispatcher: notification failed for type={$type} target={$target->getKey()} — {$e->getMessage()}");
        }
    }

    /**
     * Phase 14 FCM delivery via kreait/laravel-firebase.
     *
     * Loads the user's registered FCM tokens from the devices table
     * (created in Phase 14 migration) and sends a data-only push notification
     * to each registered device. Wrapped in try/catch — degrades to log on
     * FCM failure without surfacing the error to the caller.
     *
     * kreait/laravel-firebase must be installed (already in composer.json).
     */
    private function triggerFcm(User $target, string $type, array $payload): void
    {
        // Check devices table exists (Phase 14 migration may not have run in tests).
        try {
            $tokens = Device::where('user_id', $target->getKey())
                ->pluck('fcm_token')
                ->toArray();
        } catch (\Throwable) {
            return;
        }

        if (empty($tokens)) {
            return;
        }

        try {
            /** @var \Kreait\Firebase\Messaging\CloudMessage $message */
            $messaging = app(\Kreait\Laravel\Firebase\Facades\Firebase::class)
                ->messaging();

            $message = \Kreait\Firebase\Messaging\CloudMessage::new()
                ->withData([
                    'alert_type' => $type,
                    'payload'    => json_encode($payload, JSON_THROW_ON_ERROR),
                ]);

            foreach ($tokens as $token) {
                $messaging->send($message->withChangedTarget('token', $token));
            }
        } catch (\Throwable $e) {
            // Degrade gracefully — alert is already persisted and Reverb broadcast sent.
            Log::warning("AlertDispatcher: FCM push failed for user={$target->getKey()} type={$type} — {$e->getMessage()}");
        }
    }
}
