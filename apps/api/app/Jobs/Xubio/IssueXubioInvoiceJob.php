<?php

declare(strict_types=1);

namespace App\Jobs\Xubio;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Xubio\Dto\InvoicePayload;
use App\Services\Xubio\Exceptions\XubioRejectedException;
use App\Services\Xubio\Exceptions\XubioTimeoutException;
use App\Services\Xubio\Exceptions\XubioUnavailableException;
use App\Services\Xubio\XubioClient;
use App\Services\Xubio\XubioClienteResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Emits an invoice in Xubio for a given Invoice row.
 *
 * IDEMPOTENCY MODEL
 * =================
 * - ShouldBeUnique keyed on invoice_id prevents the same row from being
 *   processed twice concurrently.
 * - The Invoice row already carries `external_ref` set at creation; this job
 *   reuses it on every attempt.
 * - We never auto-retry on POST timeout (would risk duplicate AFIP comprobante).
 *   On timeout we mark status='reconciling' and dispatch ReconcileXubioInvoiceJob.
 *
 * BACKOFF
 * =======
 * - 3 tries on transient unavailability (5xx). Backoff 10s, 30s, 90s.
 * - Hard rejection (4xx) → no retry, status='failed_manual_review' + Director notify.
 *
 * QUEUE
 * =====
 * Runs on the 'xubio' queue (Horizon supervisor with maxProcesses=2 — Phase 0 spec).
 */
class IssueXubioInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public int $timeout = 60;

    public function __construct(public readonly string $invoiceId)
    {
        $this->onQueue('xubio');
    }

    public function uniqueId(): string
    {
        return 'issue-xubio-invoice:' . $this->invoiceId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(XubioClient $client, XubioClienteResolver $resolver): void
    {
        // Phase 1: load + atomic state guard inside a transaction with row lock.
        $invoice = DB::transaction(function (): ?Invoice {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->whereKey($this->invoiceId)->lockForUpdate()->first();

            if ($invoice === null) {
                Log::warning('IssueXubioInvoiceJob: invoice not found', ['invoice_id' => $this->invoiceId]);
                return null;
            }

            if ($invoice->status === InvoiceStatus::Success) {
                // Already done — idempotent no-op.
                return null;
            }

            // Mark as pending if it was reset, but DO NOT reset reconciling
            // (a reconcile flow is already in progress).
            if ($invoice->status !== InvoiceStatus::Reconciling) {
                $invoice->forceFill(['status' => InvoiceStatus::Pending])->save();
            }

            return $invoice;
        });

        if ($invoice === null) {
            return;
        }

        $invoice->loadMissing(['sale.items.product', 'billingEntity', 'exchangeRate']);

        // Resolve Xubio cliente id (lookup-or-create).
        try {
            $clienteId = $resolver->resolve($invoice->billingEntity);
        } catch (XubioRejectedException | XubioUnavailableException $e) {
            $this->markFailedManualReview($invoice, 'Cliente lookup/create failed: ' . $e->getMessage());
            return;
        }

        $payload = $this->buildPayload($invoice, $clienteId);

        try {
            $response = $client->emitInvoice($payload);
        } catch (XubioTimeoutException $e) {
            // CAE may have been granted — do NOT retry. Reconcile.
            DB::transaction(function () use ($invoice): void {
                $invoice->forceFill(['status' => InvoiceStatus::Reconciling])->save();
            });

            ReconcileXubioInvoiceJob::dispatch($invoice->id)->delay(now()->addSeconds(45));

            Log::warning('IssueXubioInvoiceJob: timeout, scheduled reconcile', [
                'invoice_id'   => $invoice->id,
                'external_ref' => $invoice->external_ref,
            ]);

            return;
        } catch (XubioRejectedException $e) {
            $this->markFailedManualReview($invoice, 'Xubio rejected: ' . $e->getMessage());
            return;
        } catch (XubioUnavailableException $e) {
            // Transient — let queue retry per backoff.
            throw $e;
        }

        $pdfStorageKey = $this->storePdf($client, $response->xubioId, $invoice);

        DB::transaction(function () use ($invoice, $response, $pdfStorageKey): void {
            $invoice->forceFill([
                'xubio_id'       => $response->xubioId,
                'invoice_number' => $response->invoiceNumber,
                'cae'            => $response->cae,
                'voucher_type'   => $response->voucherType,
                'amount_ars'     => $response->amountArs,
                'pdf_url'        => $pdfStorageKey,
                'status'         => InvoiceStatus::Success,
                'issued_at'      => now(),
            ])->save();
        });

        Log::info('IssueXubioInvoiceJob: invoice emitted', [
            'invoice_id' => $invoice->id,
            'xubio_id'   => $response->xubioId,
            'cae'        => $response->cae,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('IssueXubioInvoiceJob: terminal failure', [
            'invoice_id' => $this->invoiceId,
            'error'      => $e->getMessage(),
        ]);

        $invoice = Invoice::find($this->invoiceId);
        if ($invoice !== null && ! $invoice->isSuccess()) {
            $this->markFailedManualReview($invoice, 'Job exhausted retries: ' . $e->getMessage());
        }
    }

    private function markFailedManualReview(Invoice $invoice, string $reason): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice->forceFill(['status' => InvoiceStatus::FailedManualReview])->save();
        });

        $directors = \App\Models\User::query()
            ->where('role', 'director')
            ->where('is_active', true)
            ->get();

        \Illuminate\Support\Facades\Notification::send(
            $directors,
            \App\Notifications\InvoiceRequiresDirectorAction::fromInvoice($invoice, $reason),
        );
    }

    private function buildPayload(Invoice $invoice, string $clienteId): InvoicePayload
    {
        $sale = $invoice->sale;
        $voucherType = $invoice->voucher_type ?? 'B';

        $items = [];
        foreach ($sale->items as $item) {
            $items[] = [
                'descripcion'    => $item->product?->name ?? ('Producto ' . $item->product_id),
                'cantidad'       => $item->quantity_boxes,
                'precioUnitario' => (string) $item->unit_price_amount,
                'subtotal'       => (string) $item->subtotal_amount,
            ];
        }

        // amount_ars for AFIP — if the sale is USD, multiply by exchange_rate.
        $amountArs = $sale->total_currency === 'ARS'
            ? (string) $sale->total_amount
            : (string) \Brick\Math\BigDecimal::of((string) $sale->total_amount)
                ->multipliedBy((string) ($invoice->exchangeRate->rate_ars_per_usd ?? '1'))
                ->toScale(4, \Brick\Math\RoundingMode::HALF_UP);

        return new InvoicePayload(
            externalRef:  $invoice->external_ref,
            clienteId:    $clienteId,
            voucherType:  $voucherType,
            currency:     'ARS',
            amountArs:    $amountArs,
            lineItems:    $items,
            exchangeRate: $invoice->exchangeRate?->rate_ars_per_usd,
            notes:        'Venta ' . $sale->id,
        );
    }

    private function storePdf(XubioClient $client, string $xubioId, Invoice $invoice): ?string
    {
        try {
            $pdf = $client->getInvoicePdf($xubioId);
        } catch (XubioUnavailableException $e) {
            // PDF retrieval is best-effort — Xubio sometimes lags; reconcile job re-tries.
            Log::warning('IssueXubioInvoiceJob: PDF fetch failed', [
                'invoice_id' => $invoice->id,
                'xubio_id'   => $xubioId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }

        $key = sprintf('invoices/%s.pdf', $invoice->id);
        $disk = config('filesystems.default') === 's3' ? 's3' : 'local';

        try {
            Storage::disk($disk)->put($key, $pdf);
        } catch (\Throwable $e) {
            Log::warning('IssueXubioInvoiceJob: PDF store failed', [
                'invoice_id' => $invoice->id,
                'disk'       => $disk,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }

        return $key;
    }
}
