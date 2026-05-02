<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EvolutionState;
use App\Filament\Resources\PurchaseEvolutionMetricResource\Pages\ListPurchaseEvolutionMetrics;
use App\Models\PurchaseEvolutionMetric;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * PurchaseEvolutionMetricResource — Phase 10 Filament panel.
 *
 * Read-only viewer for the nightly-computed purchase evolution metrics.
 * Provides state badges, sort-by-state, and filter-by-state so Directors and
 * Distributors can identify at-risk customers at a glance.
 *
 * Visibility mirrors the RLS policies on purchase_evolution_metrics:
 *   Director     — all metrics (global view)
 *   Distributor  — zone customers (RLS handles it transparently via GUCs)
 *   Seller       — own customers (RLS handles it transparently via GUCs)
 *
 * The resource is intentionally read-only (no Create / Edit / Delete pages).
 * Data is populated exclusively by EvolutionEngine::recompute().
 */
class PurchaseEvolutionMetricResource extends Resource
{
    protected static ?string $model = PurchaseEvolutionMetric::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Evolución de Compra';

    protected static ?string $modelLabel = 'Métrica de Evolución';

    protected static ?string $pluralModelLabel = 'Evolución de Compra';

    protected static ?string $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.first_name')
                    ->label('Cliente')
                    ->formatStateUsing(fn ($state, PurchaseEvolutionMetric $record): string =>
                        $record->customer?->fullName() ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder =>
                        $query->whereHas('customer', fn ($q) => $q
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")))
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Producto')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('evolution_state')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EvolutionState $state): string => $state->label())
                    ->color(fn (EvolutionState $state): string => $state->color()),

                TextColumn::make('purchase_count')
                    ->label('Compras')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('avg_interval_days')
                    ->label('Frec. promedio (días)')
                    ->formatStateUsing(fn (?float $state): string => $state !== null ? number_format($state, 1) : '—')
                    ->sortable(),

                TextColumn::make('last_interval_days')
                    ->label('Último intervalo (días)')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? (string) $state : '—')
                    ->sortable(),

                TextColumn::make('last_purchase_date')
                    ->label('Última compra')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('computed_at')
                    ->label('Calculado')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Argentina/Buenos_Aires')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('evolution_state')
                    ->label('Estado')
                    ->options(
                        collect(EvolutionState::cases())
                            ->mapWithKeys(fn (EvolutionState $s): array => [$s->value => $s->label()])
                            ->toArray()
                    ),
            ])
            ->defaultSort('evolution_state')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseEvolutionMetrics::route('/'),
        ];
    }
}
