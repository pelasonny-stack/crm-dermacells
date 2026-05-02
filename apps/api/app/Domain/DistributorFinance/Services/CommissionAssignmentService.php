<?php

declare(strict_types=1);

namespace App\Domain\DistributorFinance\Services;

use App\Models\SellerCommissionConfig;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CommissionAssignmentService — §9.2
 *
 * Manages the versioned commission percentage that a Distributor pays to a
 * Seller for sales in a specific Zone.
 *
 * VERSIONING
 * ==========
 * A new row is inserted for each change, with effective_from indicating when
 * the new rate takes effect. The active rate for a given target date is the
 * row with the greatest effective_from <= target date.
 *
 * This means:
 *   - Changing the rate mid-month does NOT retroactively affect the current
 *     period's commission computation (the job uses the rate effective at
 *     period_start = first day of the month being computed).
 *   - Historical commission payments remain accurate because the commission_pct
 *     snapshot is stored in distributor_commission_payments at compute time.
 */
final class CommissionAssignmentService
{
    /**
     * Set (or update) the commission percentage for a (Distributor, Seller, Zone) tuple.
     *
     * Inserts a new versioned row. If a row already exists for the exact
     * (distributor_id, seller_id, zone_id, effective_from) tuple, it is updated
     * (Director can correct a same-day mistake).
     *
     * @param User   $distributor    The paying Distributor.
     * @param User   $seller         The receiving Seller (or Director with can_sell).
     * @param Zone   $zone           The zone this commission covers.
     * @param float  $pct            Fraction in [0, 1] (e.g. 0.15 = 15%).
     * @param string $setBy          ID of the user making the change.
     * @param Carbon|null $effectiveFrom  Defaults to today.
     * @return SellerCommissionConfig The created or updated config row.
     *
     * @throws InvalidArgumentException when $pct is outside [0, 1].
     */
    public function setCommissionPct(
        User $distributor,
        User $seller,
        Zone $zone,
        float $pct,
        string $setBy,
        ?Carbon $effectiveFrom = null,
    ): SellerCommissionConfig {
        if ($pct < 0.0 || $pct > 1.0) {
            throw new InvalidArgumentException(
                "Commission percentage must be between 0 and 1 (inclusive). Got: {$pct}"
            );
        }

        $from = $effectiveFrom ?? today();

        return DB::transaction(function () use ($distributor, $seller, $zone, $pct, $setBy, $from): SellerCommissionConfig {
            // Upsert by the UNIQUE key (distributor_id, seller_id, zone_id, effective_from)
            // If the exact same effective_from already exists, update the pct (Director correction).
            $existing = SellerCommissionConfig::query()
                ->where('distributor_id', $distributor->id)
                ->where('seller_id', $seller->id)
                ->where('zone_id', $zone->id)
                ->whereDate('effective_from', $from->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->commission_pct = number_format($pct, 4, '.', '');
                $existing->set_by         = $setBy;
                $existing->save();
                return $existing->fresh();
            }

            return SellerCommissionConfig::create([
                'distributor_id' => $distributor->id,
                'seller_id'      => $seller->id,
                'commission_pct' => number_format($pct, 4, '.', ''),
                'zone_id'        => $zone->id,
                'set_by'         => $setBy,
                'effective_from' => $from->toDateString(),
            ]);
        });
    }

    /**
     * Resolve the currently active commission percentage for a (Distributor, Seller, Zone) tuple.
     *
     * Returns null if no configuration exists (Distributor has not set a rate yet).
     *
     * @param User        $distributor  The paying Distributor.
     * @param User        $seller       The Seller.
     * @param Zone        $zone         The zone.
     * @param CarbonInterface|null $asOf  The target date; defaults to today.
     * @return float|null               Fraction in [0,1], or null if unconfigured.
     */
    public function currentPctFor(
        User $distributor,
        User $seller,
        Zone $zone,
        ?CarbonInterface $asOf = null,
    ): ?float {
        $targetDate = $asOf ?? today();

        $config = SellerCommissionConfig::query()
            ->where('distributor_id', $distributor->id)
            ->where('seller_id', $seller->id)
            ->where('zone_id', $zone->id)
            ->whereDate('effective_from', '<=', $targetDate->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if ($config === null) {
            return null;
        }

        return (float) $config->commission_pct;
    }
}
