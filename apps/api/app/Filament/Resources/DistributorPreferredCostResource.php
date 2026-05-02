<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PreferredCostModality;
use App\Enums\UserRole;
use App\Filament\Resources\DistributorPreferredCostResource\Pages;
use App\Models\DistributorPreferredCost;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource — DistributorPreferredCost (§9.1, §16.4)
 *
 * Director-only: create, edit, and view preferred cost configurations
 * for each (Distributor, Product) pair. Distributors cannot access this resource
 * — they see their costs via the API and the read-only DistributorAccountResource widget.
 */
class DistributorPreferredCostResource extends Resource
{
    protected static ?string $model = DistributorPreferredCost::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Costos preferenciales';

    protected static ?string $navigationGroup = 'Distribuidor Financiero';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Configuración de costo preferencial')->schema([
                Forms\Components\Select::make('distributor_id')
                    ->label('Distribuidor')
                    ->relationship('distributor', 'full_name', fn (Builder $q) =>
                        $q->where('role', UserRole::Distributor->value)
                    )
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('modality')
                    ->label('Modalidad')
                    ->options(collect(PreferredCostModality::cases())
                        ->mapWithKeys(fn (PreferredCostModality $m) => [$m->value => $m->label()])
                        ->toArray()
                    )
                    ->required()
                    ->reactive(),

                Forms\Components\TextInput::make('value')
                    ->label(fn (Forms\Get $get) =>
                        $get('modality') === PreferredCostModality::DiscountPct->value
                            ? 'Descuento (fracción 0–1, ej: 0.20 = 20%)'
                            : 'Precio fijo por caja'
                    )
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Forms\Get $get) =>
                        $get('modality') === PreferredCostModality::DiscountPct->value ? 1 : null
                    )
                    ->required(),

                Forms\Components\Select::make('currency')
                    ->label('Moneda')
                    ->options(['USD' => 'USD', 'ARS' => 'ARS'])
                    ->default('USD')
                    ->hidden(fn (Forms\Get $get) => $get('modality') === PreferredCostModality::DiscountPct->value)
                    ->required(fn (Forms\Get $get) => $get('modality') === PreferredCostModality::FixedPrice->value),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('distributor.full_name')
                    ->label('Distribuidor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('modality')
                    ->label('Modalidad')
                    ->formatStateUsing(fn (PreferredCostModality $state) => $state->label())
                    ->color(fn (PreferredCostModality $state) =>
                        $state === PreferredCostModality::FixedPrice ? 'info' : 'warning'
                    ),

                Tables\Columns\TextColumn::make('value')
                    ->label('Valor')
                    ->formatStateUsing(fn (DistributorPreferredCost $record) =>
                        $record->modality === PreferredCostModality::DiscountPct
                            ? number_format((float) $record->value * 100, 2) . '%'
                            : number_format((float) $record->value, 2) . ' ' . $record->currency
                    ),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updatedBy.full_name')
                    ->label('Por'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('distributor_id')
                    ->label('Distribuidor')
                    ->relationship('distributor', 'full_name'),

                Tables\Filters\SelectFilter::make('modality')
                    ->label('Modalidad')
                    ->options(collect(PreferredCostModality::cases())
                        ->mapWithKeys(fn (PreferredCostModality $m) => [$m->value => $m->label()])
                        ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDistributorPreferredCosts::route('/'),
            'create' => Pages\CreateDistributorPreferredCost::route('/create'),
            'edit'   => Pages\EditDistributorPreferredCost::route('/{record}/edit'),
        ];
    }

    /**
     * Mutate form data before save: fill updated_by with the authenticated Director.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected static function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();
        $data['updated_at'] = now();
        return $data;
    }
}
