<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AlertsController — Phase 10 (§10.3).
 *
 * Endpoints:
 *   GET  /api/v1/alerts/me?delivered=false   Own alerts, paginated.
 *   PATCH /api/v1/alerts/{id}/read           Mark one alert as read.
 *   GET  /api/v1/alerts/types                Director only — type catalog + counts.
 *
 * RLS enforced at the Postgres layer by migration 000004 (alerts policies).
 * The SetPostgresRlsContext middleware sets app.user_id + app.user_role GUCs
 * before each request hits the controller.
 */
class AlertsController extends Controller
{
    /**
     * GET /api/v1/alerts/me
     *
     * Returns the authenticated user's own alerts, newest first.
     * Supports ?delivered=false to filter undelivered only.
     * Paginated: 30 per page via cursor pagination.
     *
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Alert::query()->orderByDesc('created_at');

        // Directors see all alerts (RLS also allows it via alerts_director_all policy).
        // Sellers and Distributors see only their own alerts.
        if ($user->role !== UserRole::Director) {
            $query->forUser((string) $user->getKey());
        }

        if ($request->query('delivered') === 'false') {
            $query->undelivered();
        }

        $alerts = $query->cursorPaginate(30);

        return response()->json($alerts);
    }

    /**
     * PATCH /api/v1/alerts/{id}/read
     *
     * Marks a single alert as read. The user can only mark their own alerts
     * (RLS prevents access to others' rows — a 404 is returned for a
     * different user's alert ID, which avoids info-leakage on existence).
     *
     * @return JsonResponse
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $alert = Alert::forUser((string) $request->user()->getKey())
            ->findOrFail($id);

        $alert->markRead();

        return response()->json([
            'id'      => $alert->id,
            'read_at' => $alert->read_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/alerts/types
     *
     * Director only. Returns the full catalog of alert types with:
     *   - type (string)
     *   - total_count (int)
     *   - unread_count (int)
     *   - last_fired_at (ISO 8601 | null)
     *
     * @return JsonResponse
     */
    public function types(Request $request): JsonResponse
    {
        if ($request->user()->role !== UserRole::Director) {
            abort(403, 'Director access required.');
        }

        $catalog = DB::select(<<<'SQL'
            SELECT
                alert_type,
                COUNT(*)                                AS total_count,
                COUNT(*) FILTER (WHERE read_at IS NULL) AS unread_count,
                MAX(created_at)                         AS last_fired_at
            FROM alerts
            GROUP BY alert_type
            ORDER BY total_count DESC
        SQL);

        return response()->json([
            'data' => array_map(static fn ($row) => [
                'type'         => $row->alert_type,
                'total_count'  => (int) $row->total_count,
                'unread_count' => (int) $row->unread_count,
                'last_fired_at' => $row->last_fired_at,
            ], $catalog),
        ]);
    }
}
