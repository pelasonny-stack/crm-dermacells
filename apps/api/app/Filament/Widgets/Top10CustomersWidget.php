<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Top 10 Customers by Volume — Phase 14 Filament dashboard (§14.3 Salud Cartera).
 *
 * Displays the top 10 customers ordered by total delivered sale volume with a
 * trend arrow (growing / stable / decreasing) derived from purchase_evolution_metrics.
 *
 * Director-only via canView().
 */
final class Top10CustomersWidget extends TableWidget
{
    protected static ?string $heading = 'Top 10 Clientes por Volumen';

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildQuery())
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Cliente')
                    ->sortable(false),

                Tables\Columns\TextColumn::make('total_volume')
                    ->label('Volumen total')
                    ->money('ARS')
                    ->sortable(false),

                Tables\Columns\TextColumn::make('currency')
                    ->label('Moneda')
                    ->sortable(false),

                Tables\Columns\BadgeColumn::make('trend')
                    ->label('Tendencia')
                    ->colors([
                        'success' => fn ($state) => in_array($state, ['increasing', 'first_purchase'], true),
                        'warning' => fn ($state) => $state === 'stable',
                        'danger'  => fn ($state) => in_array($state, ['decreasing', 'inactive'], true),
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'increasing'     => 'Creciente',
                        'stable'         => 'Estable',
                        'decreasing'     => 'Decreciente',
                        'inactive'       => 'Inactivo',
                        'first_purchase' => 'Primera compra',
                        default          => 'Sin datos',
                    }),
            ])
            ->paginated(false);
    }

    private function buildQuery(): Builder
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('purchase_evolution_metrics', 'purchase_evolution_metrics.customer_id', '=', 'customers.id')
            ->where('sales.status', 'delivered')
            ->selectRaw('
                customers.id,
                CONCAT(customers.first_name, \' \', customers.last_name) AS full_name,
                SUM(sale_items.subtotal) AS total_volume,
                MAX(sales.currency) AS currency,
                MAX(purchase_evolution_metrics.evolution_state) AS trend
            ')
            ->groupBy('customers.id', 'customers.first_name', 'customers.last_name')
            ->orderByDesc('total_volume')
            ->limit(10);
    }
}
