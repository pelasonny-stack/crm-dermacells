<?php

declare(strict_types=1);

namespace App\Jobs\Xubio;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Notifications\XubioReconciliationFailed;
use App\Services\Xubio\XubioClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly polling reconciliation — runs at 04:00 ART.
 *
 * Targets:
 *   - Any invoice in 'reconciling' or 'pending' status whose updated_at is
 *     older than 1h. We try to find them in Xubio by external_ref; if we
 *     match, link them; if not, leave them flagged for the next cycle.
 *
 * If many rows fail in one pass, we send a single rollup notification to
 * Directors to avoid alert fatigue.
 */
class ReconcileInvoicesNightlyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('xubio');
    }

    public function uniqueId(): string
    {
        return 'reconcile-invoices-nightly:' . Carbon::today('America/Argentina/Buenos_Aires')->toDateString();
    }

    public function uniqueFor(): int
    {
        return 7200;
    }

    public function handle(XubioClient $client): void
    {
        $cutoff = now()->subHour();

        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Reconciling->value, InvoiceStatus::Pending->value])
            ->where('updated_at', '<', $cutoff)
            ->limit(500)
            ->get();

        if ($invoices->isEmpty()) {
            Log::info('ReconcileInvoicesNightlyJob: nothing to reconcile.');
            return;
        }

        $failed = [];

        foreach ($invoices as $invoice) {
            try {
                $hit = $client->findInvoiceByExternalRef($invoice->external_ref);
            } catch (\Throwable $e) {
                Log::warning('ReconcileInvoicesNightlyJob: lookup failed', [
                    'invoice_id' => $invoice->id,
                    'error'      => $e->getMessage(),
                ]);
                $failed[] = $invoice->id;
                continue;
            }

            if ($hit !== null && isset($hit['id'])) {
                DB::transaction(function () use ($invoice, $hit): void {
                    $invoice->forceFill([
                        'xubio_id'       => (string) $hit['id'],
                        'invoice_number' => (string) ($hit['numero'] ?? $hit['numeroComprobante'] ?? ''),
                        'cae'            => (string) ($hit['cae'] ?? ''),
                        'status'         => InvoiceStatus::Success,
                        'issued_at'      => $invoice->issued_at ?? now(),
                    ])->save();
                });
                continue;
            }

            $failed[] = $invoice->id;
        }

        if ($failed !== []) {
            $directors = \App\Models\User::query()
                ->where('role', 'director')
                ->where('is_active', true)
                ->get();

            Notification::send(
                $directors,
                new XubioReconciliationFailed(
                    invoiceIds: $failed,
                    detail:     'Nightly reconciliation could not match these invoices in Xubio.',
                ),
            );
        }

        Log::info('ReconcileInvoicesNightlyJob: completed', [
            'checked' => $invoices->count(),
            'failed'  => count($failed),
        ]);
    }
}
