<?php

declare(strict_types=1);

namespace App\Jobs\Xubio;

use App\Enums\CreditNoteStatus;
use App\Enums\PartialReturnStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PartialReturn;
use App\Services\Xubio\Dto\CreditNotePayload;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Emits an NC in Xubio for a partial return.
 *
 * Triggered by:
 *   - Listener OnPartialReturnAwaitingCreditNote (automatic flow)
 *   - InvoiceController::createCreditNote (manual flow)
 *
 * Resolves the parent Invoice via the PartialReturn → Sale → invoices()
 * relationship (picks the latest 'success' invoice by issued_at).
 *
 * On success the PartialReturn transitions to NcIssued.
 */
class IssueXubioCreditNoteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public int $timeout = 60;

    public function __construct(public readonly string $partialReturnId)
    {
        $this->onQueue('xubio');
    }

    public function uniqueId(): string
    {
        return 'issue-xubio-cn:' . $this->partialReturnId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(XubioClient $client, XubioClienteResolver $resolver): void
    {
        /** @var PartialReturn|null $return */
        $return = PartialReturn::with(['sale.invoices', 'saleItem.product'])
            ->find($this->partialReturnId);

        if ($return === null) {
            Log::warning('IssueXubioCreditNoteJob: partial return not found', ['partial_return_id' => $this->partialReturnId]);
            return;
        }

        if ($return->status === PartialReturnStatus::NcIssued || $return->status === PartialReturnStatus::Applied) {
            return; // idempotent no-op
        }

        $invoice = $this->resolveParentInvoice($return);

        if ($invoice === null) {
            Log::error('IssueXubioCreditNoteJob: no parent invoice found', [
                'partial_return_id' => $return->id,
                'sale_id'           => $return->sale_id,
            ]);
            return;
        }

        // Find or create the local CreditNote row first (idempotency boundary).
        $cn = $this->findOrCreateCreditNoteRow($return, $invoice);

        if ($cn->status === CreditNoteStatus::Success) {
            $this->markPartialReturnNcIssued($return);
            return;
        }

        try {
            $clienteId = $resolver->resolve($invoice->billingEntity);
        } catch (XubioRejectedException | XubioUnavailableException $e) {
            $this->markCreditNoteFailed($cn, 'Cliente resolver: ' . $e->getMessage());
            return;
        }

        $payload = new CreditNotePayload(
            externalRef:           $cn->external_ref,
            clienteId:             $clienteId,
            idComprobanteAsociado: (string) $invoice->xubio_id,
            voucherType:           $invoice->voucher_type ?? 'B',
            currency:              'ARS',
            amountArs:             (string) $cn->amount_ars,
            lineItems:             [[
                'descripcion'    => $return->saleItem?->product?->name ?? 'Producto devuelto',
                'cantidad'       => $return->quantity_boxes,
                'precioUnitario' => (string) ($return->saleItem?->unit_price_amount ?? '0'),
                'subtotal'       => (string) $cn->amount_ars,
            ]],
            exchangeRate:          $invoice->exchangeRate?->rate_ars_per_usd,
            notes:                 'NC parcial sale ' . $return->sale_id . ' return ' . $return->id,
        );

        try {
            $response = $client->emitCreditNote($payload);
        } catch (XubioTimeoutException $e) {
            DB::transaction(function () use ($cn): void {
                $cn->forceFill(['status' => CreditNoteStatus::Reconciling])->save();
            });

            // Reuse the same reconcile pattern as invoices — for NCs we leverage
            // ReconcileInvoicesNightlyJob to find them by external_ref.
            Log::warning('IssueXubioCreditNoteJob: timeout, marked reconciling', [
                'credit_note_id' => $cn->id,
                'external_ref'   => $cn->external_ref,
            ]);
            return;
        } catch (XubioRejectedException $e) {
            $this->markCreditNoteFailed($cn, 'Xubio rejected: ' . $e->getMessage());
            return;
        } catch (XubioUnavailableException $e) {
            throw $e; // queue retry
        }

        DB::transaction(function () use ($cn, $response, $return): void {
            $cn->forceFill([
                'xubio_id'   => $response->xubioId,
                'cn_number'  => $response->invoiceNumber,
                'cae'        => $response->cae,
                'amount_ars' => $response->amountArs,
                'status'     => CreditNoteStatus::Success,
                'issued_at'  => now(),
            ])->save();

            $return->forceFill(['status' => PartialReturnStatus::NcIssued])->save();
        });

        Log::info('IssueXubioCreditNoteJob: NC emitted', [
            'credit_note_id' => $cn->id,
            'xubio_id'       => $response->xubioId,
        ]);
    }

    private function resolveParentInvoice(PartialReturn $return): ?Invoice
    {
        return Invoice::query()
            ->where('sale_id', $return->sale_id)
            ->where('status', 'success')
            ->orderByDesc('issued_at')
            ->with(['billingEntity', 'exchangeRate'])
            ->first();
    }

    private function findOrCreateCreditNoteRow(PartialReturn $return, Invoice $invoice): CreditNote
    {
        return CreditNote::firstOrCreate(
            [
                'partial_return_id' => $return->id,
            ],
            [
                'invoice_id'        => $invoice->id,
                'sale_id'           => $return->sale_id,
                'sale_item_id'      => $return->sale_item_id,
                'external_ref'      => 'cn-' . $return->id . '-' . Str::ulid(),
                'amount_ars'        => (string) $return->refund_amount,
                'exchange_rate_id'  => $invoice->exchange_rate_id,
                'status'            => CreditNoteStatus::Pending,
            ],
        );
    }

    private function markCreditNoteFailed(CreditNote $cn, string $reason): void
    {
        DB::transaction(function () use ($cn): void {
            $cn->forceFill(['status' => CreditNoteStatus::FailedManualReview])->save();
        });

        $directors = \App\Models\User::query()
            ->where('role', 'director')
            ->where('is_active', true)
            ->get();

        Notification::send(
            $directors,
            new \App\Notifications\InvoiceRequiresDirectorAction(
                invoiceId:   $cn->invoice_id,
                saleId:      $cn->sale_id,
                externalRef: $cn->external_ref,
                reason:      'NC: ' . $reason,
            ),
        );
    }

    private function markPartialReturnNcIssued(PartialReturn $return): void
    {
        if ($return->status !== PartialReturnStatus::NcIssued) {
            DB::transaction(function () use ($return): void {
                $return->forceFill(['status' => PartialReturnStatus::NcIssued])->save();
            });
        }
    }
}
