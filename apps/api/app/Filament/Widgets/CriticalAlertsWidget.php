<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * CriticalAlertsWidget — count of severity=critical alerts in the last 24 hours.
 *
 * Used on the Director dashboard to surface stock-below-minimum, unusual sales,
 * and other critical system events at a glance.
 *
 * Color logic:
 *   0        → success
 *   1-2      → warning
 *   3+       → danger
 */
class CriticalAlertsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // All roles see their own alerts (filtered by RLS at DB level).
        return in_array(auth()->user()?->role, [
            UserRole::Director,
            UserRole::Distributor,
            UserRole::Seller,
        ], true);
    }

    protected function getStats(): array
    {
        $count = (int) DB::table('alerts')
            ->where('severity', 'critical')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $color = match (true) {
            $count === 0 => 'success',
            $count <= 2  => 'warning',
            default      => 'danger',
        };

        $description = $count === 0
            ? 'Sin alertas criticas en las ultimas 24h'
            : "{$count} alerta(s) critica(s) en las ultimas 24h";

        return [
            Stat::make('Alertas Criticas (24h)', (string) $count)
                ->description($description)
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($color)
                ->chart($this->hourlyChart()),
        ];
    }

    /**
     * Returns hourly counts for the last 12 hours as a sparkline.
     *
     * @return array<int, int>
     */
    private function hourlyChart(): array
    {
        $rows = DB::table('alerts')
            ->selectRaw("DATE_TRUNC('hour', created_at) AS hour, COUNT(*) AS cnt")
            ->where('severity', 'critical')
            ->where('created_at', '>=', now()->subHours(12))
            ->groupByRaw("DATE_TRUNC('hour', created_at)")
            ->orderBy('hour')
            ->pluck('cnt')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Pad to 12 data points if fewer rows exist.
        while (count($rows) < 12) {
            array_unshift($rows, 0);
        }

        return array_slice($rows, -12);
    }
}
