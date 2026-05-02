<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboards\Services\DashboardAssembler;
use App\Enums\UserRole;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * SellerDashboardWidget — Phase 14 role-scoped dashboard for Vendedoras/es.
 *
 * Visibility: Seller role only via canView().
 *
 * Shows four key cards drawn from SellerDashboardData:
 *   1. Cobrado USD-equiv mes             → /admin/payments
 *   2. Comisión acumulada                → /admin/seller-commission-dashboard (own)
 *   3. Ventas mes vs anterior (count)    → /admin/sales
 *   4. Alertas activas                   → /admin/alerts
 *
 * Data is assembled by DashboardAssembler to keep query logic centralised.
 * The widget is intentionally lightweight — heavy drill-downs live in the
 * resource list pages linked from each card's href().
 */
final class SellerDashboardWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Seller;
    }

    protected function getStats(): array
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            /** @var \App\Data\Dashboards\SellerDashboardData $data */
            $data = app(DashboardAssembler::class)->assembleForUser($user);
        } catch (\Throwable) {
            return $this->fallbackStats();
        }

        $cobradoUsd = number_format((float) $data->month_collected_usd, 2, ',', '.');
        $comision   = number_format((float) $data->commission_accumulated_usd, 2, ',', '.');

        $ventasMes    = (int) $data->sales_month_count;
        $ventasPrior  = (int) $data->sales_prior_month_count;
        $ventasDiff   = $ventasMes - $ventasPrior;
        $ventasTrend  = $ventasDiff >= 0
            ? "+{$ventasDiff} vs mes anterior"
            : "{$ventasDiff} vs mes anterior";

        $alertas = count($data->today_alerts);

        $alertColor = match (true) {
            $alertas === 0 => 'success',
            $alertas <= 2  => 'warning',
            default        => 'danger',
        };

        return [
            Stat::make('Cobrado mes (USD equiv.)', 'USD ' . $cobradoUsd)
                ->description('Pagos recibidos este mes')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success')
                ->url(url('/admin/payments')),

            Stat::make('Comision acumulada', 'USD ' . $comision)
                ->description('Comision generada en el mes')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info')
                ->url(url('/admin/seller-commission-dashboard')),

            Stat::make('Ventas del mes', (string) $ventasMes)
                ->description($ventasTrend)
                ->descriptionIcon($ventasDiff >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($ventasDiff >= 0 ? 'success' : 'warning')
                ->url(url('/admin/sales')),

            Stat::make('Alertas activas', (string) $alertas)
                ->description($alertas === 0 ? 'Sin alertas pendientes' : "{$alertas} alerta(s) hoy")
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($alertColor)
                ->url(url('/admin/alerts')),
        ];
    }

    /** @return array<int,Stat> */
    private function fallbackStats(): array
    {
        return [
            Stat::make('Cobrado mes (USD equiv.)', '—')
                ->description('Datos no disponibles')
                ->color('gray'),

            Stat::make('Comision acumulada', '—')
                ->description('Datos no disponibles')
                ->color('gray'),

            Stat::make('Ventas del mes', '—')
                ->description('Datos no disponibles')
                ->color('gray'),

            Stat::make('Alertas activas', '—')
                ->description('Datos no disponibles')
                ->color('gray'),
        ];
    }
}
