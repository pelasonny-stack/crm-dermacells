<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Dashboards\Queries\PortfolioHealthQuery;
use App\Domain\Dashboards\Queries\RankingDistributorsQuery;
use App\Domain\Dashboards\Services\DashboardAssembler;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard controller — Phase 14 (§14).
 *
 * GET /api/v1/dashboards/me
 *   Returns the role-appropriate dashboard DTO for the authenticated user.
 *   Shape is SellerDashboardData | DistributorDashboardData | DirectorDashboardData.
 *
 * GET /api/v1/dashboards/director/executive
 *   Director-only. Same as /me for Directors but explicitly documented
 *   as the "executive view" entry point.
 *
 * GET /api/v1/dashboards/director/portfolio-health?months=12
 *   Director-only drill-down. Returns portfolio evolution N months.
 *
 * GET /api/v1/dashboards/director/zone-ranking
 *   Director-only drill-down. Returns distributor ranking.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardAssembler    $assembler,
        private readonly PortfolioHealthQuery  $portfolioQuery,
        private readonly RankingDistributorsQuery $distRankingQuery,
    ) {}

    /**
     * GET /api/v1/dashboards/me
     *
     * Returns the dashboard DTO for the calling user's role.
     * Director receives DirectorDashboardData regardless of can_sell flag.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $dashboard = $this->assembler->assembleForUser($user);

        return response()->json($dashboard);
    }

    /**
     * GET /api/v1/dashboards/director/executive
     *
     * Director-only — returns DirectorDashboardData.
     * Kept as a separate endpoint so the mobile/web client can cache it
     * under a distinct route without ambiguity.
     */
    public function executive(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== UserRole::Director) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $dashboard = $this->assembler->assembleForUser($user);

        return response()->json($dashboard);
    }

    /**
     * GET /api/v1/dashboards/director/portfolio-health?months=12
     *
     * Director-only drill-down returning portfolio evolution + zone
     * penetration + at-risk customer list for the requested number of months.
     */
    public function portfolioHealth(Request $request): JsonResponse
    {
        if ($request->user()->role !== UserRole::Director) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $months = (int) $request->query('months', 12);
        $months = max(1, min(24, $months)); // Clamp to 1-24

        return response()->json([
            'evolution'       => $this->portfolioQuery->evolution($months),
            'zone_penetration' => $this->portfolioQuery->zonePenetration(),
            'at_risk_customers' => $this->portfolioQuery->atRiskCustomers(),
            'top10_customers'  => $this->portfolioQuery->top10Customers(),
        ]);
    }

    /**
     * GET /api/v1/dashboards/director/zone-ranking
     *
     * Director-only drill-down returning distributor ranking by zone volume.
     */
    public function zoneRanking(Request $request): JsonResponse
    {
        if ($request->user()->role !== UserRole::Director) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'ranking' => $this->distRankingQuery->get(),
        ]);
    }
}
