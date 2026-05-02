<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the initial global commission tier scale — §12.2.
 *
 * Three tiers effective from today (2026-05-02 at time of project setup):
 *
 *   Tier 1: USD 0      threshold → 10%  (0 – 7,500 collected)
 *   Tier 2: USD 7,500  threshold → 12%  (7,500 – 11,250 collected)
 *   Tier 3: USD 11,250 threshold → 15%  (> 11,250 collected)
 *
 * Per §12.2: the highest reached tier applies to 100% of the collected amount
 * (flat / not marginal). USD 7,500 and USD 11,250 are equivalent to 15 and 20
 * boxes at base price USD 750/box.
 *
 * Idempotent: uses ON CONFLICT DO NOTHING keyed on the natural tuple
 * (effective_from, tier_order). This requires a unique index on those two
 * columns. Since the migration does not define that constraint (to allow
 * flexible Director editing), we use a manual check here: if any row already
 * exists with the same effective_from and tier_order, the insert is skipped.
 *
 * Running this seeder multiple times is therefore safe.
 */
class CommissionScaleSeeder extends Seeder
{
    public function run(): void
    {
        $now           = now()->toDateTimeString();
        $effectiveFrom = today()->toDateString();

        $tiers = [
            ['tier_order' => 1, 'threshold_amount' => '0.0000',     'rate_pct' => '0.1000'],
            ['tier_order' => 2, 'threshold_amount' => '7500.0000',  'rate_pct' => '0.1200'],
            ['tier_order' => 3, 'threshold_amount' => '11250.0000', 'rate_pct' => '0.1500'],
        ];

        foreach ($tiers as $tier) {
            // Skip if a tier with the same effective_from + tier_order already exists.
            $exists = DB::selectOne(
                'SELECT 1 FROM commission_scale WHERE effective_from = ? AND tier_order = ? LIMIT 1',
                [$effectiveFrom, $tier['tier_order']]
            );

            if ($exists !== null) {
                continue;
            }

            DB::statement(<<<SQL
                INSERT INTO commission_scale
                    (id, tier_order, threshold_amount, threshold_currency, rate_pct, effective_from, created_by, created_at, updated_at)
                VALUES
                    (gen_random_uuid(), ?, ?, 'USD', ?, ?, NULL, ?, ?)
            SQL, [
                $tier['tier_order'],
                $tier['threshold_amount'],
                $tier['rate_pct'],
                $effectiveFrom,
                $now,
                $now,
            ]);
        }
    }
}
