<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\DistributorStockResource\Pages;
use App\Models\DistributorStock;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament resource for distributor stock — Director only.
 *
 * Read-only audit view. Allows editing minimum_stock only (Director may
 * configure alert thresholds per §16.11). Redistribution, receiving from
 * central, and reservations go through API Actions.
 */
class DistributorStockResource extends Resource
{
    protected static ?string $model = DistributorStock::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Stock Distribuidores';

    protected static ?string $modelLabel = 'Stock de Distribuidor';

    protected static ?string $pluralModelLabel = 'Stock de Distribuidores';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Distribuidor / Producto')
                ->schema([
                    Forms\Components\Select::make('distributor_id')
                        ->label('Distribuidor')
                        ->relationship('distributor', 'full_name')
                        ->disabled(),

                    Forms\Components\Select::make('product_id')
                        ->label('Producto')
                        ->relationship('product', 'name')
                        ->disabled(),
                ])->columns(2),

            Forms\Components\Section::make('Inventario (solo lectura)')
                ->schema([
                    Forms\Components\TextInput::make('total_received')->disabled()->label('Recibido'),
                    Forms\Components\TextInput::make('total_redistributed')->disabled()->label('Redistribuido'),
                    Forms\Components\TextInput::make('reserved')->disabled()->label('Reservado'),
                    Forms\Components\TextInput::make('available')->disabled()->label('Disponible'),
                ])->columns(4),

            Forms\Components\Section::make('Configuración')
                ->schema([
                    Forms\Components\TextInput::make('minimum_stock')
                        ->label('Stock mínimo (cajas)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('distributor.full_name')
                    ->label('Distribuidor')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('total_received')
                    ->label('Recibido')
                    ->numeric(),

                Tables\Columns\TextColumn::make('total_redistributed')
                    ->label('Redistribuido')
                    ->numeric(),

                Tables\Columns\TextColumn::make('reserved')
                    ->label('Reservado')
                    ->numeric(),

                Tables\Columns\TextColumn::make('available')
                    ->label('Disponible')
                    ->numeric()
                    ->color(fn (DistributorStock $record) => $record->available < $record->minimum_stock && $record->minimum_stock > 0
                        ? 'danger'
                        : 'success'),

                Tables\Columns\TextColumn::make('minimum_stock')
                    ->label('Mínimo')
                    ->numeric(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('below_minimum')
                    ->label('Solo bajo mínimo')
                    ->query(fn ($query) => $query->belowMinimum()),

                Tables\Filters\SelectFilter::make('distributor_id')
                    ->label('Distribuidor')
                    ->relationship('distributor', 'full_name'),
            ])
            ->defaultSort('distributor.full_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDistributorStock::route('/'),
            'edit'  => Pages\EditDistributorStock::route('/{record}/edit'),
        ];
    }
}
