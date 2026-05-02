<?php

declare(strict_types=1);

namespace App\Domain\Evolution\Services;

use App\Enums\EvolutionState;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseEvolutionMetric;
use App\Models\ScheduledAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EvolutionEngine — Phase 10 core service (§10.1, §10.2).
 *
 * Responsibilities:
 *  1. recompute()   — refresh the MV concurrently, then UPSERT metrics.
 *  2. stateFor()    — pure classification function for a single (Customer, Product) pair.
 *
 * STATE LOGIC (§10.2):
 *  first_purchase — purchase_count == 1 (only one delivered sale).
 *  scheduled      — has at least one active (unresolved) ScheduledAction.
 *                   Applied AFTER other states: the label overrides display but
 *                   alerts still fire (per §3.8 — "alertas siguen activas").
 *                   We store 'scheduled' so dashboards can apply the visual label.
 *  increasing     — last_interval < avg_interval (buying more frequently).
 *  stable         — last_interval within avg ± 20% band.
 *  decreasing     — last_interval > avg * 1.20.
 *  inactive       — days since last purchase > 2 × frequency_expected.
 *                   frequency_expected = customer.purchase_frequency_days
 *                   (or category default, or config default = 30d).
 *
 * The 'scheduled' state check runs last so that an otherwise-inactive customer
 * with an active scheduled_action gets classified as 'scheduled' — which
 * communicates "yes, we know, we're on it" to the dashboard consumer.
 */
class EvolutionEngine
{
    /**
     * Default fallback frequency when neither the customer nor the category
     * defines purchase_frequency_days. Used only by stateFor().
     */
    private const DEFAULT_FREQUENCY_DAYS = 30;

    /**
     * Refresh the materialized view concurrently, then UPSERT the
     * purchase_evolution_metrics table from the refreshed view.
     *
     * Returns the number of rows affected (inserted + updated).
     *
     * CONCURRENTLY: does not acquire an ExclusiveLock on the MV — reads
     * continue serving stale data until the refresh completes. Requires the
     * UNIQUE INDEX on (customer_id, product_id) present in migration 000003.
     *
     * The UPSERT uses ON CONFLICT DO UPDATE to idempotently refresh each row.
     * computed_at is always set to now() so callers can detect stale rows.
     */
    public function recompute(): int
    {
        // Step 1: refresh the materialized view.
        // Attempt a CONCURRENT refresh via the migration connection (which owns the
        // MV and is not wrapped in DatabaseTransactions). If the refresh fails (e.g.
        // the MV has no committed data yet — common in test transactions), we fall
        // through and query the base tables directly in step 2.
        $migrationConnection = config('database.default') === 'pgsql_test'
            ? 'pgsql_test_migration'
            : 'pgsql_migration';

        try {
            DB::connection($migrationConnection)->statement(
                'REFRESH MATERIALIZED VIEW CONCURRENTLY mv_customer_product_purchases'
            );
            Log::info('EvolutionEngine: materialized view refreshed concurrently.');
        } catch (\Throwable $e) {
            Log::warning('EvolutionEngine: MV refresh skipped — ' . $e->getMessage());
        }

        // Step 2: UPSERT metrics.
        // Query the base tables directly instead of the MV so that:
        //  - Test transactions (uncommitted data) are visible to this connection.
        //  - Production queries remain accurate even when the MV is momentarily stale.
        //
        // Note: sale_date is a DATE column. In Postgres, DATE - DATE yields an INTEGER
        // (number of days), NOT an interval — so no EXTRACT(EPOCH FROM ...) needed.
        $rows = DB::select(<<<'SQL'
            SELECT
                sub.customer_id,
                sub.product_id,
                COUNT(*)                                               AS purchase_count,
                MAX(sub.sale_date)                                     AS last_purchase_date,
                -- avg_interval_days: average of ALL non-null gaps (matches MV AVG(gap))
                CASE
                    WHEN COUNT(DISTINCT sub.sale_date) < 2 THEN NULL
                    ELSE ROUND(AVG(sub.interval_days)::NUMERIC, 2)
                END                                                    AS avg_interval_days,
                -- intervals_array: all gaps in chronological order (matching MV)
                ARRAY_AGG(sub.interval_days ORDER BY sub.sale_date)
                    FILTER (WHERE sub.interval_days IS NOT NULL)       AS intervals_array
            FROM (
                SELECT
                    s.customer_id,
                    si.product_id,
                    s.sale_date,
                    -- DATE - DATE returns INTEGER (days) in Postgres
                    (s.sale_date - LAG(s.sale_date) OVER (
                        PARTITION BY s.customer_id, si.product_id
                        ORDER BY s.sale_date
                    ))::float AS interval_days
                FROM sales s
                JOIN sale_items si ON si.sale_id = s.id
                WHERE s.status = 'delivered'
            ) sub
            GROUP BY sub.customer_id, sub.product_id
        SQL);

        $affected = 0;

        foreach ($rows as $row) {
            $customer = Customer::find($row->customer_id);
            $product  = Product::find($row->product_id);

            if (! $customer || ! $product) {
                Log::warning("EvolutionEngine: missing customer {$row->customer_id} or product {$row->product_id} — skipping.");
                continue;
            }

            // Parse the intervals array (Postgres returns it as a string like "{30.0,37.0}")
            $intervalsArray  = $row->intervals_array;
            $lastIntervalDays = null;
            if (is_string($intervalsArray) && $intervalsArray !== '' && $intervalsArray !== '{}') {
                // Strip braces and split
                $vals = explode(',', trim($intervalsArray, '{}'));
                $vals = array_filter($vals, fn ($v) => $v !== 'NULL' && $v !== '');
                if (count($vals) > 0) {
                    $lastIntervalDays = (int) round((float) end($vals));
                }
            }

            $metric = new PurchaseEvolutionMetric([
                'customer_id'        => $row->customer_id,
                'product_id'         => $row->product_id,
                'purchase_count'     => (int) $row->purchase_count,
                'last_purchase_date' => $row->last_purchase_date,
                'avg_interval_days'  => $row->avg_interval_days !== null ? (float) $row->avg_interval_days : null,
                'last_interval_days' => $lastIntervalDays,
            ]);

            $state = $this->stateFor($customer, $product, $metric);

            DB::statement(<<<'SQL'
                INSERT INTO purchase_evolution_metrics
                    (id, customer_id, product_id, last_purchase_date,
                     avg_interval_days, last_interval_days, purchase_count,
                     evolution_state, computed_at)
                VALUES
                    (gen_random_uuid(), :customer_id, :product_id, :last_purchase_date,
                     :avg_interval_days, :last_interval_days, :purchase_count,
                     :evolution_state, NOW())
                ON CONFLICT (customer_id, product_id) DO UPDATE SET
                    last_purchase_date = EXCLUDED.last_purchase_date,
                    avg_interval_days  = EXCLUDED.avg_interval_days,
                    last_interval_days = EXCLUDED.last_interval_days,
                    purchase_count     = EXCLUDED.purchase_count,
                    evolution_state    = EXCLUDED.evolution_state,
                    computed_at        = NOW()
            SQL, [
                'customer_id'        => $row->customer_id,
                'product_id'         => $row->product_id,
                'last_purchase_date' => $row->last_purchase_date,
                'avg_interval_days'  => $row->avg_interval_days,
                'last_interval_days' => $lastIntervalDays,
                'purchase_count'     => (int) $row->purchase_count,
                'evolution_state'    => $state->value,
            ]);

            $affected++;
        }

        Log::info("EvolutionEngine: UPSERTED {$affected} metric rows.");

        return $affected;
    }

    /**
     * Pure state classification for a (Customer, Product) pair.
     *
     * $metric is a transient (not persisted) PurchaseEvolutionMetric with
     * the fields populated from the MV row. The method is kept pure so it
     * can be called in isolation from tests with arbitrary inputs.
     *
     * @param  PurchaseEvolutionMetric  $metric  Transient metric with MV data.
     */
    public function stateFor(Customer $customer, Product $product, PurchaseEvolutionMetric $metric): EvolutionState
    {
        $purchaseCount     = $metric->purchase_count;
        $avgInterval       = $metric->avg_interval_days;
        $lastInterval      = $metric->last_interval_days;
        $lastPurchaseDate  = $metric->last_purchase_date;

        // ---- first_purchase -----------------------------------------------
        if ($purchaseCount <= 1) {
            return EvolutionState::FirstPurchase;
        }

        // ---- inactive (evaluate before interval states) -------------------
        // Uses customer-level frequency, or falls back to category/config default.
        $frequencyExpected = $this->resolveFrequency($customer);
        $daysSinceLast     = $lastPurchaseDate
            ? (int) now()->startOfDay()->diffInDays($lastPurchaseDate, false) * -1
            : null;

        if ($daysSinceLast !== null && $daysSinceLast > ($frequencyExpected * (float) config('evolution.inactive_multiplier', 2.0))) {
            // Check scheduled_action override BEFORE returning inactive
            return $this->applyScheduledOverride($customer, EvolutionState::Inactive);
        }

        // ---- interval-based states (requires avg + last interval) ---------
        if ($avgInterval !== null && $lastInterval !== null) {
            $stableBand = (float) config('evolution.stable_band_pct', 0.20);
            $lowerBound = $avgInterval * (1 - $stableBand);
            $upperBound = $avgInterval * (1 + $stableBand);

            if ($lastInterval < $lowerBound) {
                return $this->applyScheduledOverride($customer, EvolutionState::Increasing);
            }

            if ($lastInterval <= $upperBound) {
                return $this->applyScheduledOverride($customer, EvolutionState::Stable);
            }

            // lastInterval > avg * 1.20
            return $this->applyScheduledOverride($customer, EvolutionState::Decreasing);
        }

        // Fallback: enough purchases to have an avg but edge data missing
        return $this->applyScheduledOverride($customer, EvolutionState::Stable);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the expected purchase frequency in days for a customer.
     * Priority: customer override → category default → config default → 30.
     */
    private function resolveFrequency(Customer $customer): int
    {
        if ($customer->purchase_frequency_days !== null && $customer->purchase_frequency_days > 0) {
            return $customer->purchase_frequency_days;
        }

        // Category default — §16.3 "frecuencia de compra esperada por defecto por categoría"
        // Accessed via eager-loaded relationship if available.
        $category = $customer->relationLoaded('category') ? $customer->category : $customer->category()->first();

        if ($category && isset($category->default_purchase_frequency_days) && $category->default_purchase_frequency_days > 0) {
            return (int) $category->default_purchase_frequency_days;
        }

        return self::DEFAULT_FREQUENCY_DAYS;
    }

    /**
     * If the customer has an active (unresolved) ScheduledAction, override the
     * computed state to EvolutionState::Scheduled.
     *
     * Per §3.8: "las alertas de inactividad siguen activas pero con esa etiqueta
     * visual, sin generar ruido falso." The alert jobs read the stored state;
     * by persisting 'scheduled', the job can still fire the appropriate alert
     * while dashboards show the "en seguimiento" label.
     */
    private function applyScheduledOverride(Customer $customer, EvolutionState $computed): EvolutionState
    {
        $hasActiveAction = ScheduledAction::where('customer_id', $customer->id)
            ->where('is_resolved', false)
            ->exists();

        return $hasActiveAction ? EvolutionState::Scheduled : $computed;
    }
}
