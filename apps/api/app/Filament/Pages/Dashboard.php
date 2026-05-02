<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\CollectionsBySpeedometerWidget;
use App\Filament\Widgets\CriticalAlertsWidget;
use App\Filament\Widgets\DirectorPulseWidget;
use App\Filament\Widgets\DistributorDashboardWidget;
use App\Filament\Widgets\PendingAuthorizationsWidget;
use App\Filament\Widgets\SalesByZoneChart;
use App\Filament\Widgets\SellerDashboardWidget;
use App\Filament\Widgets\Top10CustomersWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dashboard page — Phase 14 role-scoped widget registry.
 *
 * Each widget self-gates via canView(). This method provides a deterministic
 * render order and keeps the full widget list in one place for easy audit.
 *
 * Visibility summary:
 *   DirectorPulseWidget          → Director only
 *   SellerDashboardWidget        → Seller only
 *   DistributorDashboardWidget   → Distributor only
 *   CriticalAlertsWidget         → all roles (filtered by RLS)
 *   PendingAuthorizationsWidget  → Director only
 *   CollectionsBySpeedometerWidget → Director + Distributor
 *   SalesByZoneChart             → Director + Distributor
 *   Top10CustomersWidget         → Director only
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            DirectorPulseWidget::class,
            SellerDashboardWidget::class,
            DistributorDashboardWidget::class,
            CriticalAlertsWidget::class,
            PendingAuthorizationsWidget::class,
            CollectionsBySpeedometerWidget::class,
            SalesByZoneChart::class,
            Top10CustomersWidget::class,
        ];
    }
}
