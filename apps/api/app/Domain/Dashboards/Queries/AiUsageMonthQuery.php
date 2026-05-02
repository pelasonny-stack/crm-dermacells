<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sums AI usage (tokens + cost) for the current calendar month.
 *
 * Uses raw DB facade per PLAN.md aggregation decision.
 * Falls back to zeroes if ai_usage table does not exist (Phase 13 not run).
 */
final class AiUsageMonthQuery
{
    /**
     * @return array{tokens:int, cost_usd:string, active:bool}
     */
    public function get(?Carbon $month = null): array
    {
        $month ??= Carbon::now();
        $start = $month->copy()->startOfMonth()->toDateTimeString();
        $end   = $month->copy()->endOfMonth()->toDateTimeString();

        try {
            $row = DB::table('ai_usage')
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('
                    SUM(total_tokens) AS total_tokens,
                    SUM(cost_usd)     AS total_cost_usd
                ')
                ->first();

            $active = (bool) DB::table('ai_settings')->value('is_active');

            return [
                'tokens'   => (int) ($row->total_tokens ?? 0),
                'cost_usd' => number_format((float) ($row->total_cost_usd ?? 0), 4, '.', ''),
                'active'   => $active,
            ];
        } catch (\Throwable) {
            // Phase 13 tables may not exist in test environments.
            return ['tokens' => 0, 'cost_usd' => '0.0000', 'active' => false];
        }
    }
}
