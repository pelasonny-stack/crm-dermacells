<?php

declare(strict_types=1);

namespace App\Jobs\DistributorFinance;

use App\Domain\Audit\Concerns\SetsRlsContext;
use App\Domain\DistributorFinance\Services\DistributorAccountService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * RecalculateDistributorAccountJob — Phase 8
 *
 * Queued job that triggers a full idempotent recalculation of a Distributor's
 * financial account (balance + gross margin).
 *
 * UNIQUENESS
 * ==========
 * Implements ShouldBeUnique so that multiple events (SaleStatusChanged,
 * SettlementConfirmed, etc.) triggered in rapid succession for the same
 * Distributor are collapsed into a single job execution. This prevents
 * thundering herd on the account row.
 *
 * The unique key is the Distributor's UUID, so concurrent recalculations
 * for *different* Distributors proceed in parallel.
 *
 * QUEUE
 * =====
 * Runs on the 'default' queue. Not time-critical enough for 'high'. The
 * last_recalculated_at timestamp on the account row serves as a staleness
 * indicator for the UI.
 *
 * RLS CONTEXT
 * ===========
 * Uses SetsRlsContext trait with the Director system-level role so the job
 * can access all distributor_account rows regardless of normal RLS scope.
 * The actingUserId is the Distributor whose account is being recalculated.
 */
class RecalculateDistributorAccountJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable, SetsRlsContext;

    /**
     * Maximum attempts before failure.
     */
    public int $tries = 3;

    /**
     * Backoff in seconds between attempts.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90];

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly string $distributorId,
    ) {}

    /**
     * The unique ID for this job — scoped to the Distributor being recalculated.
     * If a job for this Distributor is already queued, the new dispatch is silently dropped.
     */
    public function uniqueId(): string
    {
        return 'recalculate-distributor-account:' . $this->distributorId;
    }

    /**
     * Execute the recalculation.
     */
    public function handle(DistributorAccountService $service): void
    {
        $distributor = User::findOrFail($this->distributorId);

        // Set RLS context: the job runs with distributor role scope
        // so the account query scopes correctly within the transaction.
        $this->withRlsContext($distributor->id, $distributor->role->value, function () use ($service, $distributor): void {
            $service->recalculate($distributor);
        });
    }
}
