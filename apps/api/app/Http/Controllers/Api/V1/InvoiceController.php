<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreateCreditNoteRequest;
use App\Http\Requests\Billing\CreateInvoiceRequest;
use App\Jobs\Xubio\IssueXubioCreditNoteJob;
use App\Jobs\Xubio\IssueXubioInvoiceJob;
use App\Models\CreditNote;
use App\Models\CustomerBillingEntity;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 6 — Billing endpoints.
 *
 *   POST   /invoices                       — initiate invoice (idempotent, returns 202)
 *   GET    /invoices/{invoice}             — invoice detail
 *   POST   /invoices/{invoice}/credit-notes — manual NC (Director-initiated)
 *   GET    /invoices/{invoice}/pdf         — proxy to S3 PDF
 *
 * Errors follow RFC 7807 problem+json.
 */
class InvoiceController extends Controller
{
    // ------------------------------------------------------------------
    // POST /invoices
    // ------------------------------------------------------------------

    public function store(CreateInvoiceRequest $request): JsonResponse
    {
        $sale = Sale::findOrFail($request->validated('sale_id'));
        $billingEntity = CustomerBillingEntity::findOrFail($request->validated('billing_entity_id'));

        // Defensive: billing entity must belong to the sale's customer.
        if ($billingEntity->customer_id !== $sale->customer_id) {
            return $this->problem(
                422,
                'BILLING_ENTITY_MISMATCH',
                'The billing entity does not belong to the sale customer.',
            );
        }

        $voucherType = $request->validated('voucher_type')
            ?? $this->voucherTypeForIvaCondition($billingEntity);

        $invoice = DB::transaction(function () use ($sale, $billingEntity, $voucherType, $request): Invoice {
            return Invoice::create([
                'sale_id'           => $sale->id,
                'billing_entity_id' => $billingEntity->id,
                'external_ref'      => 'sale-' . $sale->id . '-' . Str::ulid(),
                'voucher_type'      => $voucherType,
                'exchange_rate_id'  => $sale->exchange_rate_id,
                'status'            => InvoiceStatus::Pending,
                'issued_by'         => $request->user()?->id,
            ]);
        });

        IssueXubioInvoiceJob::dispatch($invoice->id);

        return response()->json([
            'data' => [
                'id'           => $invoice->id,
                'sale_id'      => $invoice->sale_id,
                'status'       => $invoice->status->value,
                'external_ref' => $invoice->external_ref,
            ],
        ], 202);
    }

    // ------------------------------------------------------------------
    // GET /invoices/{invoice}
    // ------------------------------------------------------------------

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['sale', 'billingEntity', 'creditNotes', 'exchangeRate']);

        return response()->json(['data' => $this->serialize($invoice)]);
    }

    // ------------------------------------------------------------------
    // POST /invoices/{invoice}/credit-notes
    // ------------------------------------------------------------------

    public function storeCreditNote(
        CreateCreditNoteRequest $request,
        Invoice $invoice,
    ): JsonResponse {
        if (! $invoice->isSuccess()) {
            return $this->problem(
                409,
                'INVOICE_NOT_READY_FOR_NC',
                'The invoice must be in success status before issuing a credit note.',
            );
        }

        $cn = DB::transaction(function () use ($request, $invoice): CreditNote {
            return CreditNote::create([
                'invoice_id'        => $invoice->id,
                'sale_id'           => $invoice->sale_id,
                'sale_item_id'      => $request->validated('sale_item_id'),
                'partial_return_id' => $request->validated('partial_return_id'),
                'external_ref'      => 'cn-manual-' . $invoice->id . '-' . Str::ulid(),
                'amount_ars'        => (string) $request->validated('amount_ars'),
                'exchange_rate_id'  => $invoice->exchange_rate_id,
                'status'            => CreditNoteStatus::Pending,
                'issued_by'         => $request->user()?->id,
            ]);
        });

        // If linked to a partial_return we re-use the partial-return CN job.
        // Otherwise the manual NC is dispatched via a thin inline job — for now
        // it shares the IssueXubioCreditNoteJob path by impersonating a partial
        // return id when applicable; manual NCs without partial_return_id need
        // a follow-up Phase 7/16 job. Documented as open issue.
        if ($cn->partial_return_id !== null) {
            IssueXubioCreditNoteJob::dispatch($cn->partial_return_id);
        }

        return response()->json([
            'data' => [
                'id'              => $cn->id,
                'invoice_id'      => $cn->invoice_id,
                'status'          => $cn->status->value,
                'external_ref'    => $cn->external_ref,
                'amount_ars'      => $cn->amount_ars,
            ],
        ], 202);
    }

    // ------------------------------------------------------------------
    // GET /invoices/{invoice}/pdf
    // ------------------------------------------------------------------

    public function pdf(Invoice $invoice): Response|JsonResponse
    {
        if (! $invoice->isSuccess() || $invoice->pdf_url === null) {
            return $this->problem(
                404,
                'INVOICE_PDF_UNAVAILABLE',
                'The PDF for this invoice is not yet available.',
            );
        }

        $disk = config('filesystems.default') === 's3' ? 's3' : 'local';

        try {
            $pdf = Storage::disk($disk)->get($invoice->pdf_url);
        } catch (\Throwable $e) {
            return $this->problem(500, 'INVOICE_PDF_FETCH_FAILED', 'Could not fetch PDF: ' . $e->getMessage());
        }

        return response($pdf ?? '', 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="invoice-%s.pdf"', $invoice->id),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function voucherTypeForIvaCondition(CustomerBillingEntity $entity): string
    {
        $iva = $entity->iva_condition?->value ?? '';

        // Heuristic per AFIP: IVA Responsable Inscripto → A, Consumidor Final → B, Exterior → C.
        return match (true) {
            str_contains(strtolower($iva), 'responsable')  => 'A',
            str_contains(strtolower($iva), 'exterior')     => 'C',
            default                                        => 'B',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(Invoice $invoice): array
    {
        return [
            'id'                => $invoice->id,
            'sale_id'           => $invoice->sale_id,
            'billing_entity_id' => $invoice->billing_entity_id,
            'xubio_id'          => $invoice->xubio_id,
            'external_ref'      => $invoice->external_ref,
            'invoice_number'    => $invoice->invoice_number,
            'voucher_type'      => $invoice->voucher_type,
            'cae'               => $invoice->cae,
            'amount_ars'        => $invoice->amount_ars,
            'pdf_url'           => $invoice->pdf_url,
            'status'            => $invoice->status->value,
            'issued_at'         => $invoice->issued_at?->toIso8601String(),
            'credit_notes'      => $invoice->creditNotes->map(fn (CreditNote $c) => [
                'id'           => $c->id,
                'status'       => $c->status->value,
                'amount_ars'   => $c->amount_ars,
                'cn_number'    => $c->cn_number,
                'cae'          => $c->cae,
                'external_ref' => $c->external_ref,
            ])->all(),
        ];
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function problem(int $status, string $code, string $detail, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'type'   => "https://crm.dermacells.com/problems/{$code}",
            'title'  => $code,
            'status' => $status,
            'detail' => $detail,
            'code'   => $code,
        ], $extra), $status)->header('Content-Type', 'application/problem+json');
    }
}
