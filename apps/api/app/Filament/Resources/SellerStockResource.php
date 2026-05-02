<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\SellerStockResource\Pages;
use App\Models\SellerStock;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament resource for seller stock — Director only (read-only audit view).
 *
 * Allows editing minimum_stock only. All stock mutations go through API Actions.
 */
class SellerStockResource extends Resource
{
    protected static ?string $model = SellerStock::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Stock Vendedores';

    protected static ?string $modelLabel = 'Stock de Vendedor';

    protected static ?string $pluralModelLabel = 'Stock de Vendedores';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 13;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    /**
     * Seller views own stock (RLS); Distributor views zone sellers' stock;
     * Director sees all.
     */
    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, [
            UserRole::Director,
            UserRole::Distributor,
            UserRole::Seller,
        ], true);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Vendedor / Producto')
                ->schema([
                    Forms\Components\Select::make('seller_id')
                        ->label('Vendedor')
                        ->relationship('seller', 'full_name')
                        ->disabled(),

                    Forms\Components\Select::make('product_id')
                        ->label('Producto')
                        ->relationship('product', 'name')
                        ->disabled(),
                ])->columns(2),

            Forms\Components\Section::make('Inventario (solo lectura)')
                ->schema([
                    Forms\Components\TextInput::make('boxes')->disabled()->label('Cajas'),
                    Forms\Components\TextInput::make('loose_units')->disabled()->label('Unidades sueltas'),
                    Forms\Components\TextInput::make('reserved_boxes')->disabled()->label('Cajas reservadas'),
                    Forms\Components\TextInput::make('reserved_units')->disabled()->label('Unidades reservadas'),
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
                Tables\Columns\TextColumn::make('seller.full_name')
                    ->label('Vendedor')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('boxes')
                    ->label('Cajas')
                    ->numeric(),

                Tables\Columns\TextColumn::make('loose_units')
                    ->label('Unidades')
                    ->numeric(),

                Tables\Columns\TextColumn::make('reserved_boxes')
                    ->label('Reservadas')
                    ->numeric(),

                Tables\Columns\TextColumn::make('available_boxes')
                    ->label('Disponible')
                    ->numeric()
                    ->getStateUsing(fn (SellerStock $record) => $record->availableBoxes())
                    ->color(fn (SellerStock $record) => $record->availableBoxes() < $record->minimum_stock && $record->minimum_stock > 0
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

                Tables\Filters\SelectFilter::make('seller_id')
                    ->label('Vendedor')
                    ->relationship('seller', 'full_name'),

                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name'),
            ])
            ->defaultSort('seller.full_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSellerStock::route('/'),
            'edit'  => Pages\EditSellerStock::route('/{record}/edit'),
        ];
    }
}
