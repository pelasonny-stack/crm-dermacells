<?php

declare(strict_types=1);

namespace App\Domain\Commissions\Seller\Services;

use App\Domain\Commissions\Seller\Data\CommissionBreakdown;
use App\Domain\Commissions\Seller\Data\CommissionResult;
use App\Enums\UserRole;
use App\Models\CommissionTier;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Commission calculator for Vendedores — §12.2.
 *
 * Tier resolution algorithm (escalón único):
 *   1. Accumulate all non-reversed payments for the seller in the target
 *      calendar month, converting ARS amounts to USD-equivalent using the
 *      exchange rate recorded on each payment (payment.exchange_rate.rate_ars_per_usd).
 *   2. Determine the highest tier the accumulated USD-equivalent reaches.
 *   3. Apply that tier's rate_pct over 100% of the accumulated amount (not
 *      progressive/marginal).
 *   4. Split the resulting commission proportionally by original currency:
 *        commission_ars = total_ars_raw × tier_rate
 *        commission_usd = total_usd_raw × tier_rate
 *      This keeps dual-currency integrity (§7.4) — the customer accounts are
 *      never touched; only the commission payout uses this split.
 *
 * Directors never earn commissions regardless of the can_sell flag (§2.4, §12.3).
 * When the seller's role is Director this service returns a zero-value result
 * with is_director=true immediately — no payment queries are executed.
 *
 * Anticipos (is_advance=true) are counted in the month they were received
 * (§7.3, §12.2) — no special handling is required; the query simply filters
 * by payment_date, so anticipos fall into the correct month automatically.
 *
 * Reversed payments (reversed=true) are excluded from accumulation.
 *
 * The constructor receives a Collection of CommissionTier rows pre-loaded by
 * the caller (e.g. CommissionController or a scheduled job). This design makes
 * the service stateless and independently testable without a DB connection.
 * The caller is responsible for loading tiers via:
 *
 *   CommissionTier::activeOn($month)->get()
 *
 * and injecting them into the constructor. Tiers must be ordered ASC by
 * tier_order (CommissionTier::scopeActiveOn guarantees this).
 *
 * @see \App\Domain\Commissions\Seller\Data\CommissionResult
 * @see \App\Models\CommissionTier
 */
final class CommissionCalculatorService
{
    /**
     * @param  Collection<int, CommissionTier>  $tiers  Active commission tiers ordered by tier_order ASC.
     */
    public function __construct(
        private readonly Collection $tiers,
    ) {}

    /**
     * Calculate the commission for a Vendedor in a given calendar month.
     *
     * All money arithmetic uses brick/money with HALF_UP rounding on the
     * final multiplication step only. Intermediate USD-equivalent sums use
     * BigDecimal to avoid rounding until the last possible moment.
     *
     * @param  User    $seller  The Vendedor whose commissions are being calculated.
     * @param  Carbon  $month   Any date within the target month (only year/month are used).
     *
     * @return CommissionResult  Immutable result DTO.
     */
    public function calculateForMonth(User $seller, Carbon $month): CommissionResult
    {
        // §12.3 — Directors never earn commissions regardless of can_sell flag.
        if ($seller->role === UserRole::Director) {
            return $this->zeroResult(isDirector: true);
        }

        $startOfMonth = $month->copy()->startOfMonth();
        $endOfMonth   = $month->copy()->endOfMonth();

        // Load payments for the seller in the month.
        // Joins through sales (seller FK) and exchange_rates (for ARS→USD conversion).
        // Includes is_advance=true payments — they count in the month of receipt (§7.3).
        // Excludes reversed=true payments (§12.2).
        //
        // NOTE: Phase 7 payments table is assumed to exist with the shape:
        //   payments.id, payments.sale_id, payments.amount, payments.currency,
        //   payments.payment_date, payments.exchange_rate_id, payments.is_advance,
        //   payments.reversed
        // sales.seller_id links each payment to the Vendedor.
        // exchange_rates.rate_ars_per_usd is the ARS/USD rate at time of payment.
        $rows = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('exchange_rates', 'exchange_rates.id', '=', 'payments.exchange_rate_id')
            ->where('sales.seller_id', $seller->id)
            ->whereBetween('payments.payment_date', [
                $startOfMonth->toDateString(),
                $endOfMonth->toDateString(),
            ])
            ->where('payments.reversed', false)
            ->select([
                'payments.id',
                'payments.amount',
                'payments.currency',
                'payments.payment_date',
                'payments.is_advance',
                'exchange_rates.rate_ars_per_usd',
                'sales.zone_id',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return $this->zeroResult();
        }

        // Accumulate per-zone totals and overall currency totals.
        // BigDecimal used throughout to avoid float rounding until Money construction.
        /** @var array<string, array{ars: BigDecimal, usd: BigDecimal, usd_equiv: BigDecimal}> $byZone */
        $byZone      = [];
        $totalArs    = BigDecimal::zero();
        $totalUsd    = BigDecimal::zero();
        $totalUsdEquiv = BigDecimal::zero();

        foreach ($rows as $row) {
            $amount   = BigDecimal::of((string) $row->amount);
            $currency = (string) $row->currency;
            $zoneId   = (string) $row->zone_id;

            if (! isset($byZone[$zoneId])) {
                $byZone[$zoneId] = [
                    'ars'      => BigDecimal::zero(),
                    'usd'      => BigDecimal::zero(),
                    'usd_equiv' => BigDecimal::zero(),
                ];
            }

            if ($currency === 'ARS') {
                // Convert to USD-equivalent for tier resolution only.
                // rate_ars_per_usd = how many ARS per 1 USD (e.g. 900.0000).
                // USD-equiv = ARS amount / rate.
                $rate     = BigDecimal::of((string) $row->rate_ars_per_usd);
                $usdEquiv = $amount->dividedBy($rate, 10, RoundingMode::HALF_UP);

                $byZone[$zoneId]['ars']       = $byZone[$zoneId]['ars']->plus($amount);
                $byZone[$zoneId]['usd_equiv'] = $byZone[$zoneId]['usd_equiv']->plus($usdEquiv);
                $totalArs                     = $totalArs->plus($amount);
                $totalUsdEquiv                = $totalUsdEquiv->plus($usdEquiv);
            } else {
                // USD payment: 1:1 for tier resolution and native USD commission.
                $byZone[$zoneId]['usd']       = $byZone[$zoneId]['usd']->plus($amount);
                $byZone[$zoneId]['usd_equiv'] = $byZone[$zoneId]['usd_equiv']->plus($amount);
                $totalUsd                     = $totalUsd->plus($amount);
                $totalUsdEquiv                = $totalUsdEquiv->plus($amount);
            }
        }

        // Resolve the highest tier reached (escalón único).
        $tierRate = $this->resolveTierRate($totalUsdEquiv);

        // Apply tier rate to raw currency totals for commission split (§12.2).
        // RoundingMode::HALF_UP is passed to Money::of because the BigDecimal
        // product may have more than 2 decimal places and brick/money's default
        // context (DefaultContext) requires UNNECESSARY rounding.
        $commissionArs = Money::of(
            $totalArs->multipliedBy($tierRate, RoundingMode::HALF_UP),
            'ARS',
            null,
            RoundingMode::HALF_UP,
        );
        $commissionUsd = Money::of(
            $totalUsd->multipliedBy($tierRate, RoundingMode::HALF_UP),
            'USD',
            null,
            RoundingMode::HALF_UP,
        );

        // Load zone names in one query for breakdown labels.
        $zoneIds   = array_keys($byZone);
        $zoneNames = DB::table('zones')
            ->whereIn('id', $zoneIds)
            ->pluck('name', 'id')
            ->all();

        // Build per-zone breakdown array.
        $breakdown = [];
        foreach ($byZone as $zoneId => $zoneTotals) {
            $zoneArs    = $zoneTotals['ars'];
            $zoneUsd    = $zoneTotals['usd'];
            $zoneEquiv  = $zoneTotals['usd_equiv'];

            $breakdown[] = new CommissionBreakdown(
                zone_id:        $zoneId,
                zone_name:      $zoneNames[$zoneId] ?? $zoneId,
                collected_ars:  Money::of($zoneArs->toScale(4, RoundingMode::HALF_UP), 'ARS', null, RoundingMode::HALF_UP),
                collected_usd:  Money::of($zoneUsd->toScale(4, RoundingMode::HALF_UP), 'USD', null, RoundingMode::HALF_UP),
                usd_equivalent: Money::of($zoneEquiv->toScale(4, RoundingMode::HALF_UP), 'USD', null, RoundingMode::HALF_UP),
                commission_ars: Money::of(
                    $zoneArs->multipliedBy($tierRate, RoundingMode::HALF_UP),
                    'ARS',
                    null,
                    RoundingMode::HALF_UP,
                ),
                commission_usd: Money::of(
                    $zoneUsd->multipliedBy($tierRate, RoundingMode::HALF_UP),
                    'USD',
                    null,
                    RoundingMode::HALF_UP,
                ),
            );
        }

        return new CommissionResult(
            accumulated_usd:   Money::of($totalUsdEquiv->toScale(4, RoundingMode::HALF_UP), 'USD', null, RoundingMode::HALF_UP),
            tier_rate:         BigDecimal::of($tierRate),
            commission_ars:    $commissionArs,
            commission_usd:    $commissionUsd,
            breakdown_by_zone: $breakdown,
            is_director:       false,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine the applicable commission rate for the given accumulated
     * USD-equivalent total.
     *
     * Tiers must be ordered by tier_order ASC (lowest threshold first).
     * The algorithm walks from the highest tier downward and returns the
     * first tier whose threshold the accumulated total meets or exceeds.
     *
     * If no tiers are configured, returns 0.0000 (no commission).
     */
    private function resolveTierRate(BigDecimal $accumulatedUsdEquiv): string
    {
        if ($this->tiers->isEmpty()) {
            return '0.0000';
        }

        // Walk tiers from highest to lowest (reverse tier_order).
        $sorted = $this->tiers->sortByDesc('tier_order');

        foreach ($sorted as $tier) {
            $threshold = $tier->getAttribute('threshold_amount') !== null
                ? BigDecimal::of((string) $tier->getAttribute('threshold_amount'))
                : BigDecimal::zero();

            if ($accumulatedUsdEquiv->isGreaterThanOrEqualTo($threshold)) {
                return (string) $tier->getAttribute('rate_pct');
            }
        }

        // Below the floor tier — return the lowest tier rate.
        return (string) $this->tiers->sortBy('tier_order')->first()?->getAttribute('rate_pct') ?? '0.0000';
    }

    /**
     * Build a zero-valued CommissionResult for Directors or months with no payments.
     */
    private function zeroResult(bool $isDirector = false): CommissionResult
    {
        return new CommissionResult(
            accumulated_usd:   Money::zero('USD'),
            tier_rate:         BigDecimal::zero(),
            commission_ars:    Money::zero('ARS'),
            commission_usd:    Money::zero('USD'),
            breakdown_by_zone: [],
            is_director:       $isDirector,
        );
    }
}
