<?php

declare(strict_types=1);

namespace App\Jobs\Xubio;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Notifications\InvoiceRequiresDirectorAction;
use App\Services\Xubio\XubioClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Reconciles an invoice against Xubio after a POST timeout.
 *
 * Strategy:
 *   1. GET /FacturaVenta?externalRef=... — find by our idempotency token.
 *   2. If found → link xubio_id + cae + status='success'.
 *   3. If not found → status='failed_manual_review' + Director notification.
 *
 * ShouldBeUnique keyed on invoice_id prevents concurrent reconciles.
 */
class ReconcileXubioInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300, 600];

    public int $timeout = 60;

    public function __construct(public readonly string $invoiceId)
    {
        $this->onQueue('xubio');
    }

    public function uniqueId(): string
    {
        return 'reconcile-xubio-invoice:' . $this->invoiceId;
    }

    public function uniqueFor(): int
    {
        return 1800;
    }

    public function handle(XubioClient $client): void
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::find($this->invoiceId);
        if ($invoice === null) {
            Log::warning('ReconcileXubioInvoiceJob: invoice missing', ['invoice_id' => $this->invoiceId]);
            return;
        }

        if ($invoice->status === InvoiceStatus::Success) {
            return; // already reconciled by another path
        }

        $hit = $client->findInvoiceByExternalRef($invoice->external_ref);

        if ($hit !== null && isset($hit['id'])) {
            DB::transaction(function () use ($invoice, $hit): void {
                $invoice->forceFill([
                    'xubio_id'       => (string) $hit['id'],
                    'invoice_number' => (string) ($hit['numero'] ?? $hit['numeroComprobante'] ?? ''),
                    'cae'            => (string) ($hit['cae'] ?? ''),
                    'voucher_type'   => (string) ($hit['tipoComprobante'] ?? $invoice->voucher_type),
                    'amount_ars'     => (string) ($hit['total'] ?? $invoice->amount_ars ?? '0'),
                    'status'         => InvoiceStatus::Success,
                    'issued_at'      => now(),
                ])->save();
            });

            Log::info('ReconcileXubioInvoiceJob: matched and linked', [
                'invoice_id'   => $invoice->id,
                'external_ref' => $invoice->external_ref,
                'xubio_id'     => $hit['id'],
            ]);

            return;
        }

        // Not found — mark for manual review and notify Directors.
        DB::transaction(function () use ($invoice): void {
            $invoice->forceFill(['status' => InvoiceStatus::FailedManualReview])->save();
        });

        $directors = \App\Models\User::query()
            ->where('role', 'director')
            ->where('is_active', true)
            ->get();

        Notification::send(
            $directors,
            InvoiceRequiresDirectorAction::fromInvoice(
                $invoice,
                'Reconciliación: la factura no fue encontrada en Xubio por external_ref después del timeout.',
            ),
        );

        Log::error('ReconcileXubioInvoiceJob: not found in Xubio, manual review required', [
            'invoice_id'   => $invoice->id,
            'external_ref' => $invoice->external_ref,
        ]);
    }
}
