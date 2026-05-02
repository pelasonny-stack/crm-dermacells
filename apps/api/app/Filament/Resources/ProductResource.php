<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Resource for managing the LiveCells product catalogue — §5.1, §16.4.
 *
 * Visibility: Director-only. canAccess() gates the resource so Sellers and
 * Distributors cannot see or reach the product configuration screens.
 *
 * The base_price is stored as two columns (amount + currency) but presented as
 * a pair of form fields for clarity. The MoneyCast handles the hydration when
 * reading the model back.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static ?string $navigationGroup = 'Configuracion';

    protected static ?int $navigationSort = 10;

    /**
     * Only Directors may access this resource (§16.4).
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del producto')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255)
                        ->unique(Product::class, 'name', ignoreRecord: true),

                    Forms\Components\TextInput::make('units_per_box')
                        ->label('Unidades por caja')
                        ->required()
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->default(5),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true),
                ])
                ->columns(3),

            Forms\Components\Section::make('Precio base')
                ->schema([
                    Forms\Components\TextInput::make('base_price_amount')
                        ->label('Monto')
                        ->required()
                        ->numeric()
                        ->minValue(0.0001)
                        ->step(0.0001)
                        ->default(750),

                    Forms\Components\Select::make('base_price_currency')
                        ->label('Moneda')
                        ->required()
                        ->options([
                            'USD' => 'USD — Dólar estadounidense',
                            'ARS' => 'ARS — Peso argentino',
                        ])
                        ->default('USD'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('units_per_box')
                    ->label('Unidades/caja')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_price_amount')
                    ->label('Precio base')
                    ->formatStateUsing(
                        fn (Product $record): string =>
                            number_format((float) $record->base_price_amount, 2) . ' ' . $record->base_price_currency
                    )
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Activo'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activo'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
