<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Commissions\Seller\Services\CommissionCalculatorService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CommissionTier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Commission endpoints — Phase 9 (§12.2).
 *
 * Three endpoints:
 *
 *   GET /api/v1/commissions/me?month=YYYY-MM
 *       Vendedor or Distribuidor sees their own real-time commission accrual.
 *       Director receives 403 (they never earn commissions — §12.3).
 *
 *   GET /api/v1/commissions/{seller_id}?month=YYYY-MM
 *       Director only. Calculates commissions for any Vendedor by UUID.
 *
 *   GET /api/v1/commissions/me/breakdown?month=YYYY-MM
 *       Same as /me but the response includes per-zone breakdown_by_zone detail.
 *       Useful for the Vendedor dashboard "Mi mes" section.
 *
 * All endpoints accept a `month` query parameter in YYYY-MM format.
 * When omitted, defaults to the current calendar month.
 *
 * Commission tiers are loaded fresh per request so that Director updates to
 * the commission_scale table take effect immediately without a cache flush.
 * Tiers are filtered to those effective on or before the first day of the
 * requested month (CommissionTier::scopeActiveOn).
 */
final class CommissionController extends Controller
{
    /**
     * GET /api/v1/commissions/me
     *
     * Vendedor or Distribuidor sees their own accumulated commission for the month.
     * Directors are blocked with 403 — they never earn commissions (§12.3).
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === UserRole::Director) {
            return response()->json([
                'type'   => 'https://dermacells.com.ar/errors/forbidden',
                'title'  => 'Forbidden',
                'status' => 403,
                'code'   => 'DIRECTOR_NO_COMMISSION',
                'detail' => 'Directors do not earn commissions (§12.3).',
            ], 403);
        }

        $month  = $this->resolveMonth($request->query('month'));
        $result = $this->buildCalculator($month)->calculateForMonth($user, $month);

        return response()->json([
            'data' => array_merge(
                $result->toArray(),
                ['breakdown_by_zone' => []],  // /me omits zone detail; use /me/breakdown
            ),
            'meta' => ['month' => $month->format('Y-m')],
        ]);
    }

    /**
     * GET /api/v1/commissions/{seller_id}
     *
     * Director-only endpoint to view any Vendedor's commission for a month.
     * Returns 403 for non-Director callers.
     */
    public function show(Request $request, string $seller_id): JsonResponse
    {
        /** @var User $caller */
        $caller = $request->user();

        if ($caller->role !== UserRole::Director) {
            return response()->json([
                'type'   => 'https://dermacells.com.ar/errors/forbidden',
                'title'  => 'Forbidden',
                'status' => 403,
                'code'   => 'DIRECTOR_ONLY',
                'detail' => 'Only Directors may view other sellers\' commissions.',
            ], 403);
        }

        $seller = User::findOrFail($seller_id);
        $month  = $this->resolveMonth($request->query('month'));
        $result = $this->buildCalculator($month)->calculateForMonth($seller, $month);

        return response()->json([
            'data' => $result->toArray(),
            'meta' => [
                'month'     => $month->format('Y-m'),
                'seller_id' => $seller->id,
            ],
        ]);
    }

    /**
     * GET /api/v1/commissions/me/breakdown
     *
     * Same as /me but includes the full per-zone breakdown.
     * Directors receive 403.
     */
    public function breakdown(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === UserRole::Director) {
            return response()->json([
                'type'   => 'https://dermacells.com.ar/errors/forbidden',
                'title'  => 'Forbidden',
                'status' => 403,
                'code'   => 'DIRECTOR_NO_COMMISSION',
                'detail' => 'Directors do not earn commissions (§12.3).',
            ], 403);
        }

        $month  = $this->resolveMonth($request->query('month'));
        $result = $this->buildCalculator($month)->calculateForMonth($user, $month);

        return response()->json([
            'data' => $result->toArray(),  // includes breakdown_by_zone
            'meta' => ['month' => $month->format('Y-m')],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a YYYY-MM string into a Carbon pointing to the first of that month.
     * Falls back to the current month when the parameter is absent or invalid.
     */
    private function resolveMonth(mixed $monthParam): Carbon
    {
        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            try {
                return Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth();
            } catch (\Exception) {
                // Fall through to current month default.
            }
        }

        return Carbon::now()->startOfMonth();
    }

    /**
     * Build a CommissionCalculatorService loaded with tiers effective on the
     * requested month.
     *
     * Using scopeActiveOn(start_of_month) picks the tier set whose effective_from
     * is <= the first day of the month, which matches the Director-configured
     * "apply from month X" semantics.
     */
    private function buildCalculator(Carbon $month): CommissionCalculatorService
    {
        $tiers = CommissionTier::activeOn($month->startOfMonth())->get();

        return new CommissionCalculatorService($tiers);
    }
}
