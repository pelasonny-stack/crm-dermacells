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
        // Step 1: refresh the view without blocking reads
        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_customer_product_purchases');

        Log::info('EvolutionEngine: materialized view refreshed.');

        // Step 2: UPSERT metrics from the refreshed view.
        // State classification is done in PHP via stateFor() after bulk load;
        // this avoids encoding the classification logic in SQL (simpler to test).
        $rows = DB::select(<<<'SQL'
            SELECT
                mv.customer_id,
                mv.product_id,
                mv.total_purchases   AS purchase_count,
                mv.last_date         AS last_purchase_date,
                mv.avg_interval      AS avg_interval_days,
                CASE
                    WHEN array_length(mv.intervals_array, 1) >= 1
                    THEN mv.intervals_array[array_length(mv.intervals_array, 1)]
                    ELSE NULL
                END                  AS last_interval_days
            FROM mv_customer_product_purchases mv
        SQL);

        $affected = 0;

        foreach ($rows as $row) {
            $customer = Customer::find($row->customer_id);
            $product  = Product::find($row->product_id);

            if (! $customer || ! $product) {
                Log::warning("EvolutionEngine: missing customer {$row->customer_id} or product {$row->product_id} — skipping.");
                continue;
            }

            $metric = new PurchaseEvolutionMetric([
                'customer_id'        => $row->customer_id,
                'product_id'         => $row->product_id,
                'purchase_count'     => (int) $row->purchase_count,
                'last_purchase_date' => $row->last_purchase_date,
                'avg_interval_days'  => $row->avg_interval_days !== null ? (float) $row->avg_interval_days : null,
                'last_interval_days' => $row->last_interval_days !== null ? (int) $row->last_interval_days : null,
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
                'last_interval_days' => $row->last_interval_days,
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
