<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies all Directors that an invoice could not be reconciled with Xubio
 * after a timeout / retry cycle and requires manual investigation.
 *
 * Channels:
 *   - database (in-app inbox)
 *   - broadcast (Reverb private-director — surfaced in Director dashboard)
 */
class InvoiceRequiresDirectorAction extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $saleId,
        public readonly string $externalRef,
        public readonly string $reason,
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
            'type'         => 'invoice_requires_director_action',
            'invoice_id'   => $this->invoiceId,
            'sale_id'      => $this->saleId,
            'external_ref' => $this->externalRef,
            'reason'       => $this->reason,
            'title'        => 'Factura requiere revisión manual',
            'body'         => sprintf(
                'No se pudo confirmar la emisión de la factura %s en Xubio. Motivo: %s',
                $this->externalRef,
                $this->reason,
            ),
            'occurred_at'  => now()->toIso8601String(),
        ];
    }

    public static function fromInvoice(Invoice $invoice, string $reason): self
    {
        return new self(
            invoiceId:   $invoice->id,
            saleId:      $invoice->sale_id,
            externalRef: $invoice->external_ref,
            reason:      $reason,
        );
    }
}
