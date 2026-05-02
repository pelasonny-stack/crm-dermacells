<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fetches the top N unread alerts for a user ordered by severity + created_at.
 *
 * Severity ordering: critical > warning > info.
 * Uses raw DB facade per PLAN.md aggregation decision.
 * RLS (SET LOCAL app.user_id) is enforced by the request-layer middleware —
 * this query operates inside the same transaction and reads only rows the
 * current app.user_id is allowed to see.
 */
final class TodayAlertsQuery
{
    public function get(string $userId, int $limit = 20): Collection
    {
        return DB::table('alerts')
            ->where('target_user_id', $userId)
            ->whereNull('read_at')
            ->orderByRaw(
                "CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END ASC"
            )
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get([
                'id',
                'alert_type',
                'severity',
                'payload_json',
                'reference_entity_type',
                'reference_entity_id',
                'created_at',
                'delivered',
                'delivered_at',
                'read_at',
            ]);
    }
}
