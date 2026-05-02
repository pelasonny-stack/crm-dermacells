<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\CentralStockResource\Pages;
use App\Models\CentralStock;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament resource for the central stock (bodega Dermacells) — Director only.
 *
 * The `available` column is read-only (generated in Postgres). Editing only
 * allows adjusting minimum_stock threshold. Imports and dispatches are
 * performed via API endpoints (POST /v1/stock/central/imports and /dispatches)
 * which run the Actions with proper validation and movement logging.
 */
class CentralStockResource extends Resource
{
    protected static ?string $model = CentralStock::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Stock Central';

    protected static ?string $modelLabel = 'Stock Central';

    protected static ?string $pluralModelLabel = 'Stock Central';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Producto')
                ->schema([
                    Forms\Components\Select::make('product_id')
                        ->label('Producto')
                        ->relationship('product', 'name')
                        ->required()
                        ->disabled(fn ($record) => $record !== null), // read-only on edit
                ])->columns(1),

            Forms\Components\Section::make('Configuración')
                ->schema([
                    Forms\Components\TextInput::make('minimum_stock')
                        ->label('Stock mínimo (cajas)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                ])->columns(1),

            Forms\Components\Section::make('Inventario actual (solo lectura)')
                ->schema([
                    Forms\Components\TextInput::make('total_imported')
                        ->label('Total importado')
                        ->disabled(),

                    Forms\Components\TextInput::make('total_dispatched')
                        ->label('Total despachado')
                        ->disabled(),

                    Forms\Components\TextInput::make('available')
                        ->label('Disponible')
                        ->disabled(),
                ])->columns(3)
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('total_imported')
                    ->label('Importado')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_dispatched')
                    ->label('Despachado')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('available')
                    ->label('Disponible')
                    ->numeric()
                    ->sortable()
                    ->color(fn (CentralStock $record) => $record->available < $record->minimum_stock && $record->minimum_stock > 0
                        ? 'danger'
                        : 'success'),

                Tables\Columns\TextColumn::make('minimum_stock')
                    ->label('Mínimo')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\IconColumn::make('below_minimum')
                    ->label('Bajo mínimo')
                    ->getStateUsing(fn (CentralStock $record) => $record->available < $record->minimum_stock && $record->minimum_stock > 0)
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Última actualización')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('below_minimum')
                    ->label('Solo bajo mínimo')
                    ->query(fn ($query) => $query->belowMinimum()),
            ])
            ->defaultSort('product.name')
            ->poll('30s'); // Auto-refresh for real-time monitoring
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCentralStock::route('/'),
            'edit'  => Pages\EditCentralStock::route('/{record}/edit'),
        ];
    }
}
