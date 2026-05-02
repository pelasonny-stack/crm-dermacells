<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ZoneResource\Pages;
use App\Models\Zone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ZoneResource — CRUD para zonas de distribucion (§16.2).
 *
 * Visibility: Director-only.
 *
 * Key business rules reflected in the form:
 *   - distributor_id = NULL → zona directa (stock despachado por Director).
 *   - A zone cannot be deleted if it has active customers (soft-check via
 *     customer count badge).
 *   - Assigning a Distributor to a zone changes cash flow routing for all
 *     customers in that zone (§7.2).
 *
 * Navigation group: Distribucion.
 */
class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Zonas';

    protected static ?string $modelLabel = 'Zona';

    protected static ?string $pluralModelLabel = 'Zonas';

    protected static ?string $navigationGroup = 'Distribucion';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos de la zona')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre de la zona')
                        ->required()
                        ->maxLength(255)
                        ->unique(Zone::class, 'name', ignoreRecord: true),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activa')
                        ->default(true),
                ])
                ->columns(2),

            Forms\Components\Section::make('Distribuidor asignado')
                ->description('Dejar en blanco para configurar como zona directa Dermacells (el Director despacha directamente a los Vendedores).')
                ->schema([
                    Forms\Components\Select::make('distributor_id')
                        ->label('Distribuidor')
                        ->relationship(
                            name: 'distributor',
                            titleAttribute: 'full_name',
                            modifyQueryUsing: fn (Builder $query) => $query
                                ->where('role', UserRole::Distributor->value)
                                ->where('is_active', true),
                        )
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Solo usuarios con rol Distribuidor pueden asignarse a una zona.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Zona')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('distributor.full_name')
                    ->label('Distribuidor')
                    ->placeholder('Zona directa')
                    ->sortable(),

                Tables\Columns\TextColumn::make('customers_count')
                    ->label('Clientes')
                    ->counts('customers')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activa'),

                Tables\Filters\Filter::make('direct_zones')
                    ->label('Solo zonas directas')
                    ->query(fn (Builder $query): Builder => $query->whereNull('distributor_id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListZones::route('/'),
            'create' => Pages\CreateZone::route('/create'),
            'edit'   => Pages\EditZone::route('/{record}/edit'),
        ];
    }
}
