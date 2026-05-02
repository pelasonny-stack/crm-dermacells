<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use App\Domain\Commissions\Seller\Data\CommissionResult;
use App\Domain\Commissions\Seller\Services\CommissionCalculatorService;
use App\Models\CommissionTier;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Thin wrapper around Phase 9's CommissionCalculatorService for dashboard use.
 *
 * Loads active tiers once and delegates calculation to the canonical service,
 * ensuring the dashboard shows the same result as the /commissions/me endpoint.
 */
final class MonthlyCommissionQuery
{
    public function get(User $seller, ?Carbon $month = null): CommissionResult
    {
        $month ??= Carbon::now();

        $tiers = CommissionTier::activeOn($month)->get();

        $service = new CommissionCalculatorService($tiers);

        return $service->calculateForMonth($seller, $month);
    }
}
