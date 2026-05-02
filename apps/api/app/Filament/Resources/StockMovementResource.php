<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\StockMovementResource\Pages;
use App\Models\StockMovement;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only audit view of all stock movements — Director only.
 *
 * No create/edit/delete actions are exposed: movements are append-only
 * and must go through their respective Action classes.
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Movimientos de Stock';

    protected static ?string $modelLabel = 'Movimiento';

    protected static ?string $pluralModelLabel = 'Movimientos de Stock';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 14;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]); // Read-only resource — no form needed
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('movement_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('quantity_boxes')
                    ->label('Cajas')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity_units')
                    ->label('Unidades')
                    ->numeric(),

                Tables\Columns\TextColumn::make('from_entity_type')
                    ->label('Origen')
                    ->formatStateUsing(fn ($state, StockMovement $record) => $state
                        ? "{$state}" . ($record->from_entity_id ? " ({$record->from_entity_id})" : '')
                        : '—'),

                Tables\Columns\TextColumn::make('to_entity_type')
                    ->label('Destino')
                    ->formatStateUsing(fn ($state, StockMovement $record) => $state
                        ? "{$state}" . ($record->to_entity_id ? " ({$record->to_entity_id})" : '')
                        : '—'),

                Tables\Columns\TextColumn::make('reference_doc')
                    ->label('Referencia')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('createdBy.full_name')
                    ->label('Registrado por')
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('movement_type')
                    ->label('Tipo de movimiento')
                    ->options(\App\Enums\StockMovementType::class),

                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockMovements::route('/'),
        ];
    }
}
