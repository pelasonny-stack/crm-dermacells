<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboards\Services\DashboardAssembler;
use App\Enums\UserRole;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * DistributorDashboardWidget — Phase 14 role-scoped dashboard for Distribuidores.
 *
 * Visibility: Distributor role only via canView().
 *
 * Shows four key cards drawn from DistributorDashboardData:
 *   1. Ventas zona mes (cajas)           → /admin/sales
 *   2. Top vendedor de la zona           → /admin/sales (filtered view)
 *   3. Saldo a rendir Dermacells (ARS)   → /admin/distributor-accounts
 *   4. Alertas zona activas              → /admin/alerts
 *
 * Data assembled by DashboardAssembler; card hrefs open filtered list pages.
 */
final class DistributorDashboardWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Distributor;
    }

    protected function getStats(): array
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            /** @var \App\Data\Dashboards\DistributorDashboardData $data */
            $data = app(DashboardAssembler::class)->assembleForUser($user);
        } catch (\Throwable) {
            return $this->fallbackStats();
        }

        $cajasZona   = (int) $data->zone_sales_month_boxes;
        $montoZona   = number_format((float) $data->zone_sales_month_amount, 2, ',', '.');

        // Top seller: first entry in seller_ranking sorted by revenue.
        $topSeller = 'Sin datos';
        if (! empty($data->seller_ranking)) {
            $first     = $data->seller_ranking[0];
            $topSeller = (string) ($first['seller_name'] ?? $first['full_name'] ?? 'Sin datos');
        }

        $saldoArs = number_format((float) $data->saldo_a_rendir_ars, 2, ',', '.');

        $alertasZona = count($data->zone_stock_alerts);
        $alertColor  = match (true) {
            $alertasZona === 0 => 'success',
            $alertasZona <= 2  => 'warning',
            default            => 'danger',
        };

        return [
            Stat::make('Ventas zona mes', $cajasZona . ' cajas')
                ->description('$ ' . $montoZona)
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('success')
                ->url(url('/admin/sales')),

            Stat::make('Top vendedor zona', $topSeller)
                ->description('Mayor volumen del mes')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('info')
                ->url(url('/admin/sales')),

            Stat::make('Saldo a rendir (ARS)', '$ ' . $saldoArs)
                ->description('Balance pendiente con Dermacells')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color((float) $data->saldo_a_rendir_ars > 0 ? 'warning' : 'success')
                ->url(url('/admin/distributor-accounts')),

            Stat::make('Alertas zona', (string) $alertasZona)
                ->description($alertasZona === 0 ? 'Sin alertas activas' : "{$alertasZona} alerta(s) de stock")
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($alertColor)
                ->url(url('/admin/alerts')),
        ];
    }

    /** @return array<int,Stat> */
    private function fallbackStats(): array
    {
        return [
            Stat::make('Ventas zona mes', '—')
                ->description('Datos no disponibles')
                ->color('gray'),

            Stat::make('Top vendedor zona', '—')
                ->description('Datos no disponibles')
                ->color('gray'),

            Stat::make('Saldo a rendir (ARS)', '—')
                ->description('Datos no disponibles')
                ->color('gray'),

            Stat::make('Alertas zona', '—')
                ->description('Datos no disponibles')
                ->color('gray'),
        ];
    }
}
