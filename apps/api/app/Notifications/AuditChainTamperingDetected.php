<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notifies Directors and ops when `audit:verify` detects an HMAC chain
 * mismatch in the audit_log table.
 *
 * Channels used:
 *   - database : persisted notification for the Filament notification centre
 *   - pagerduty: raw HTTP POST to PagerDuty Events API v2 for immediate paging
 *
 * This class intentionally does NOT extend MailMessage to avoid depending on
 * SMTP being healthy during an incident. PagerDuty provides the reliable
 * out-of-band alert; database channel ensures auditability.
 *
 * Usage (dispatched by VerifyAuditChain command on non-zero exit):
 *   Notification::send($directors, new AuditChainTamperingDetected($context));
 *
 * PagerDuty Events API v2 docs:
 *   https://developer.pagerduty.com/api-reference/YXBpOjI3NDgyNjU-pager-duty-v2-events-api
 */
class AuditChainTamperingDetected extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array{
     *   id?: int|string,
     *   occurred_at?: string,
     *   reason?: string,
     *   expected?: string,
     *   actual?: string,
     * } $context  Data from the failing audit row
     */
    public function __construct(
        private readonly array $context = [],
    ) {
        // Use the critical queue so Horizon processes this immediately
        $this->onQueue('critical');
    }

    /**
     * Defines the delivery channels for each notifiable.
     *
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'pagerduty'];
    }

    /**
     * Database channel — persisted in the notifications table for the
     * Filament notification centre (visible to Director immediately on login).
     */
    public function toDatabase(mixed $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title'   => 'ALERTA CRITICA: Integridad del audit_log comprometida',
            'message' => $this->buildHumanMessage(),
            'context' => $this->context,
            'level'   => 'critical',
            'action'  => 'Investigar inmediatamente y congelar escrituras si se confirma.',
        ]);
    }

    /**
     * PagerDuty channel — implemented as a raw HTTP call to PagerDuty
     * Events API v2. No external package required.
     *
     * If PAGERDUTY_INTEGRATION_KEY is not configured, the notification logs
     * a warning but does NOT throw — the database channel still records the
     * alert for human review.
     *
     * Note: This method is called by the notification system when the channel
     * name matches `pagerduty` via a custom notification channel. Register
     * `App\Channels\PagerDutyChannel` in AppServiceProvider to wire it up:
     *
     *   Notification::extend('pagerduty', fn ($app) => new \App\Channels\PagerDutyChannel());
     *
     * For simplicity, the actual HTTP call is made here in a way that the
     * channel dispatcher will call toArray() → send it via the generic
     * `pagerduty` channel. The actual dispatch is done in VerifyAuditChain
     * directly to avoid channel registration complexity.
     *
     * @return array<string, mixed>
     */
    public function toPagerDuty(mixed $notifiable): array
    {
        return $this->buildPagerDutyPayload();
    }

    /**
     * Dispatches the PagerDuty alert directly. Call this when you cannot use
     * the channel system (e.g., from VerifyAuditChain::handle()).
     *
     * This is a static convenience method so the command can call it without
     * needing a full notifiable model.
     */
    public static function dispatchToPagerDuty(array $context): void
    {
        $integrationKey = (string) config('services.pagerduty.integration_key', '');

        if ($integrationKey === '') {
            Log::warning('AuditChainTamperingDetected: PAGERDUTY_INTEGRATION_KEY not configured — skipping PagerDuty alert');
            return;
        }

        $payload = (new self($context))->buildPagerDutyPayload();

        try {
            $response = Http::timeout(10)
                ->retry(3, sleepMilliseconds: 500)
                ->post('https://events.pagerduty.com/v2/enqueue', $payload);

            if ($response->failed()) {
                Log::error('AuditChainTamperingDetected: PagerDuty API returned error', [
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                    'context' => $context,
                ]);
            } else {
                Log::info('AuditChainTamperingDetected: PagerDuty alert dispatched', [
                    'dedup_key' => $response->json('dedup_key'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::critical('AuditChainTamperingDetected: Failed to reach PagerDuty', [
                'error'   => $e->getMessage(),
                'context' => $context,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function buildHumanMessage(): string
    {
        $id         = $this->context['id']          ?? 'desconocido';
        $occurredAt = $this->context['occurred_at'] ?? 'desconocido';
        $reason     = $this->context['reason']      ?? 'sin detalle';

        return sprintf(
            'La verificación HMAC de audit_log detectó una discrepancia en la fila id=%s (occurred_at=%s). Razón: %s. Investigar inmediatamente.',
            $id,
            $occurredAt,
            $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPagerDutyPayload(): array
    {
        $integrationKey = (string) config('services.pagerduty.integration_key', '');

        return [
            'routing_key'  => $integrationKey,
            'event_action' => 'trigger',
            'dedup_key'    => 'audit-chain-mismatch-' . ($this->context['id'] ?? 'unknown'),
            'payload' => [
                'summary'   => 'CRITICAL: audit_log HMAC chain mismatch — possible data tampering',
                'source'    => 'crm-dermacells-' . app()->environment(),
                'severity'  => 'critical',
                'timestamp' => now()->toIso8601String(),
                'custom_details' => [
                    'environment'       => app()->environment(),
                    'audit_row_id'      => $this->context['id']          ?? null,
                    'audit_occurred_at' => $this->context['occurred_at'] ?? null,
                    'reason'            => $this->context['reason']       ?? null,
                    'expected_hash'     => $this->context['expected']     ?? null,
                    'actual_hash'       => $this->context['actual']       ?? null,
                    'runbook'           => 'https://github.com/OWNER/crm-dermacells/blob/main/docs/DR_RUNBOOK.md#3-audit-log-integrity-restore',
                ],
            ],
            'links' => [[
                'href' => 'https://admin.crm.dermacells.com.ar/admin',
                'text' => 'Filament Admin Panel',
            ]],
        ];
    }
}
