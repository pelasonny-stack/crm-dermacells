<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Generic Xubio reconciliation failure (nightly job) — used when the daily
 * polling pass detects N invoices/CNs that drifted out of sync with Xubio.
 */
class XubioReconciliationFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int,string> $invoiceIds
     */
    public function __construct(
        public readonly array $invoiceIds,
        public readonly string $detail,
    ) {
        $this->onQueue('default');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string,mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /** @return array<string,mixed> */
    public function toBroadcast(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'xubio_reconciliation_failed',
            'invoice_ids' => $this->invoiceIds,
            'detail'      => $this->detail,
            'title'       => 'Reconciliación nocturna Xubio con errores',
            'body'        => sprintf('%d facturas requieren revisión: %s', count($this->invoiceIds), $this->detail),
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
