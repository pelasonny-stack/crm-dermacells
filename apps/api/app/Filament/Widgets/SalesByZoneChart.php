<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * SalesByZone bar chart — Phase 14 Filament dashboard (§14.3).
 *
 * Displays last 6 months of delivered sales aggregated per zone.
 * Each zone is a separate bar series, months are X-axis labels.
 *
 * Visibility: Director-only via canView().
 */
final class SalesByZoneChart extends ChartWidget
{
    protected static ?string $heading = 'Ventas por Zona — Ultimos 6 Meses';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->where('sales.status', 'delivered')
            ->where('sales.sale_date', '>=', now()->subMonths(6)->startOfMonth()->toDateString())
            ->selectRaw("
                TO_CHAR(DATE_TRUNC('month', sales.sale_date), 'YYYY-MM') AS month,
                zones.name AS zone_name,
                SUM(sale_items.quantity_boxes) AS boxes
            ")
            ->groupByRaw("DATE_TRUNC('month', sales.sale_date), zones.name")
            ->orderByRaw("DATE_TRUNC('month', sales.sale_date) ASC")
            ->get();

        // Build months list (last 6 months, always 6 labels even if no data).
        $months = collect();
        for ($i = 5; $i >= 0; $i--) {
            $months->push(now()->subMonths($i)->format('Y-m'));
        }

        // Group by zone name.
        $byZone  = $rows->groupBy('zone_name');
        $datasets = [];
        $colors   = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
        $colorIdx = 0;

        foreach ($byZone as $zoneName => $zoneRows) {
            $byMonth = $zoneRows->keyBy('month');
            $data    = $months->map(fn ($m) => (int) ($byMonth[$m]?->boxes ?? 0))->toArray();

            $datasets[] = [
                'label'           => $zoneName,
                'data'            => $data,
                'backgroundColor' => $colors[$colorIdx % count($colors)],
            ];

            $colorIdx++;
        }

        return [
            'datasets' => $datasets,
            'labels'   => $months->toArray(),
        ];
    }
}
