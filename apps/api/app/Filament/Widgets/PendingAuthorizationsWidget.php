<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * PendingAuthorizationsWidget — shows count of pending authorization requests.
 *
 * Clicking the stat navigates the Director to the authorization_requests queue.
 * Color thresholds:
 *   0        → success (green)
 *   1-3      → warning (amber)
 *   4+       → danger (red)
 *
 * Data is read directly via DB facade (no Eloquent hydration overhead for a
 * simple COUNT query on the dashboard).
 */
class PendingAuthorizationsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /** Refresh every 60 seconds if Livewire polling is configured. */
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    protected function getStats(): array
    {
        $count = (int) DB::table('authorization_requests')
            ->where('status', 'pending')
            ->count();

        $color = match (true) {
            $count === 0 => 'success',
            $count <= 3  => 'warning',
            default      => 'danger',
        };

        $description = match ($count) {
            0       => 'Sin solicitudes pendientes',
            1       => '1 solicitud requiere aprobacion',
            default => "{$count} solicitudes requieren aprobacion",
        };

        return [
            Stat::make('Autorizaciones Pendientes', (string) $count)
                ->description($description)
                ->descriptionIcon('heroicon-m-clock')
                ->color($color)
                ->url(url('/admin/authorization-requests')),
        ];
    }
}
