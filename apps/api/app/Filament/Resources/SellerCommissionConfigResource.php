<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\SellerCommissionConfigResource\Pages;
use App\Models\SellerCommissionConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource — SellerCommissionConfig (§9.2, §16.5)
 *
 * Director reads and modifies commission configurations for any Distributor-Seller pair.
 * The versioned rows are listed newest-first per (distributor, seller, zone) group.
 */
class SellerCommissionConfigResource extends Resource
{
    protected static ?string $model = SellerCommissionConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-percent-badge';

    protected static ?string $navigationLabel = 'Config. comisiones';

    protected static ?string $navigationGroup = 'Distribuidor Financiero';

    protected static ?int $navigationSort = 4;

    /**
     * Director configures commission rates. Distributor reads own config (read-only).
     */
    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, [
            UserRole::Director,
            UserRole::Distributor,
        ], true);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Configuración de comisión')->schema([
                Forms\Components\Select::make('distributor_id')
                    ->label('Distribuidor')
                    ->options(fn () => \App\Models\User::query()
                        ->where('role', UserRole::Distributor->value)
                        ->orderBy('full_name')
                        ->pluck('full_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('seller_id')
                    ->label('Vendedor')
                    ->options(fn () => \App\Models\User::query()
                        ->whereIn('role', [UserRole::Seller->value, UserRole::Director->value])
                        ->orderBy('full_name')
                        ->pluck('full_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('zone_id')
                    ->label('Zona')
                    ->options(fn () => \App\Models\Zone::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Forms\Components\TextInput::make('commission_pct')
                    ->label('% de comisión (fracción 0–1, ej: 0.15 = 15%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(1)
                    ->step(0.0001)
                    ->required(),

                Forms\Components\DatePicker::make('effective_from')
                    ->label('Vigente desde')
                    ->required()
                    ->default(today()),
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

                Tables\Columns\TextColumn::make('seller.full_name')
                    ->label('Vendedor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('zone.name')
                    ->label('Zona'),

                Tables\Columns\TextColumn::make('commission_pct')
                    ->label('Comisión')
                    ->formatStateUsing(fn (string $state) => number_format((float) $state * 100, 2) . '%'),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('Vigente desde')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('setBy.full_name')
                    ->label('Configurado por'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('distributor_id')
                    ->label('Distribuidor')
                    ->relationship('distributor', 'full_name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('effective_from', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSellerCommissionConfigs::route('/'),
            'create' => Pages\CreateSellerCommissionConfig::route('/create'),
            'edit'   => Pages\EditSellerCommissionConfig::route('/{record}/edit'),
        ];
    }

    /**
     * Inject set_by before create/save.
     */
    protected static function mutateFormDataBeforeSave(array $data): array
    {
        $data['set_by'] = auth()->id();
        return $data;
    }
}
