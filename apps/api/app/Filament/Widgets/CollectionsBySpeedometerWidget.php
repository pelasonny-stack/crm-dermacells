<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Collections Semaphore Widget — Phase 14 Filament dashboard (§14.3 Financiero).
 *
 * Shows global pending-collection semaphore:
 *   - Al dia     (due > 48h from now)
 *   - Proximo a vencer (due within next 48h)
 *   - Vencidos   (due date in the past)
 *
 * Uses a single aggregate SQL query. Director-only.
 */
final class CollectionsBySpeedometerWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Semaforo de Cobranzas';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    protected function getStats(): array
    {
        $now   = now();
        $in48h = now()->addHours(48);

        $row = DB::table('sales')
            ->whereIn('status', ['confirmed', 'delivered'])
            ->whereNotNull('due_date')
            ->whereRaw(
                'total_amount > COALESCE((
                    SELECT SUM(p.amount) FROM payments p
                    WHERE p.sale_id = sales.id AND p.reversed = false AND p.currency = sales.currency
                ), 0)'
            )
            ->selectRaw("
                SUM(CASE WHEN due_date > ? THEN 1 ELSE 0 END)                    AS al_dia,
                SUM(CASE WHEN due_date BETWEEN ? AND ? THEN 1 ELSE 0 END)         AS proximo,
                SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END)                    AS vencido
            ", [$in48h, $now, $in48h, $now])
            ->first();

        return [
            Stat::make('Al dia', (int) ($row->al_dia ?? 0))
                ->description('Ventas con cobro al dia')
                ->color('success')
                ->descriptionIcon('heroicon-m-check-circle'),

            Stat::make('Proximo a vencer', (int) ($row->proximo ?? 0))
                ->description('Vencen en las proximas 48h')
                ->color('warning')
                ->descriptionIcon('heroicon-m-clock'),

            Stat::make('Vencidos', (int) ($row->vencido ?? 0))
                ->description('Cobros vencidos sin saldar')
                ->color('danger')
                ->descriptionIcon('heroicon-m-exclamation-triangle'),
        ];
    }
}
