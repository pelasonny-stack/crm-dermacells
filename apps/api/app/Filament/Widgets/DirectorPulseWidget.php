<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Director Pulse Widget — Phase 14 Filament dashboard (§14.3).
 *
 * Displays today's key metrics for the Director:
 *   - Sales delivered today (count + amount)
 *   - Cobros today (ARS + USD)
 *   - Pending authorizations count
 *
 * Reads from mv_director_pulse_today MV (refreshed every 5 min by
 * RefreshDashboardMatViewsJob) with a live fallback for the first run.
 *
 * Visibility: Director-only via canView().
 */
final class DirectorPulseWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    protected function getStats(): array
    {
        $pulse = $this->loadPulse();

        return [
            Stat::make('Ventas entregadas hoy', $pulse['sales_count'])
                ->description('$ ' . number_format((float) $pulse['sales_amount'], 2, ',', '.'))
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('success'),

            Stat::make('Cobros ARS hoy', '$ ' . number_format((float) $pulse['cobros_ars'], 2, ',', '.'))
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('info'),

            Stat::make('Cobros USD hoy', 'USD ' . number_format((float) $pulse['cobros_usd'], 2, ',', '.'))
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('info'),

            Stat::make('Autorizaciones pendientes', $pulse['pending_auths'])
                ->descriptionIcon('heroicon-m-clock')
                ->color($pulse['pending_auths'] > 0 ? 'warning' : 'success'),
        ];
    }

    /** @return array<string,mixed> */
    private function loadPulse(): array
    {
        try {
            $row = DB::table('mv_director_pulse_today')
                ->where('pulse_date', now()->toDateString())
                ->first();

            if ($row) {
                $pendingAuths = (int) DB::table('authorization_requests')
                    ->where('status', 'pending')
                    ->count();

                return [
                    'sales_count'  => (int) $row->sales_count,
                    'sales_amount' => (string) $row->sales_amount,
                    'cobros_ars'   => (string) $row->cobros_ars,
                    'cobros_usd'   => (string) $row->cobros_usd,
                    'pending_auths' => $pendingAuths,
                ];
            }
        } catch (\Throwable) {
            // MV not available yet — fall through to live query.
        }

        // Live fallback
        $today = now()->toDateString();

        $sales = DB::table('sales')
            ->where('sales.status', 'delivered')
            ->whereDate('sales.sale_date', $today)
            ->selectRaw('COUNT(*) AS cnt, SUM(sales.total_amount) AS amount')
            ->first();

        $cobros = DB::table('payments')
            ->where('reversed', false)
            ->whereDate('payment_date', $today)
            ->selectRaw("
                SUM(CASE WHEN amount_currency = 'ARS' THEN amount_amount ELSE 0 END) AS ars,
                SUM(CASE WHEN amount_currency = 'USD' THEN amount_amount ELSE 0 END) AS usd
            ")
            ->first();

        $pendingAuths = (int) DB::table('authorization_requests')
            ->where('status', 'pending')
            ->count();

        return [
            'sales_count'  => (int) ($sales->cnt ?? 0),
            'sales_amount' => (string) ($sales->amount ?? '0'),
            'cobros_ars'   => (string) ($cobros->ars ?? '0'),
            'cobros_usd'   => (string) ($cobros->usd ?? '0'),
            'pending_auths' => $pendingAuths,
        ];
    }
}
