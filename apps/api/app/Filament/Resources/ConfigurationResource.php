<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ConfigurationResource\Pages;
use App\Models\Configuration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * ConfigurationResource — generic key/value editor for the `configurations` table.
 *
 * Visibility: Director-only.
 *
 * Used for settings that do not warrant a dedicated resource: stock minimum
 * defaults, alert thresholds, evolution engine parameters. Keys have unique
 * constraints at DB level — duplicate keys will be caught.
 *
 * The AlertsThresholds and SystemConfig pages provide a more ergonomic UX for
 * the most common settings; this resource is the power-user escape hatch.
 *
 * Navigation group: Configuracion.
 */
class ConfigurationResource extends Resource
{
    protected static ?string $model = Configuration::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Configuraciones';

    protected static ?string $modelLabel = 'Configuracion';

    protected static ?string $pluralModelLabel = 'Configuraciones';

    protected static ?string $navigationGroup = 'Configuracion';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Configuracion')
                ->schema([
                    Forms\Components\Select::make('group_name')
                        ->label('Grupo')
                        ->required()
                        ->options([
                            'general'   => 'General',
                            'stock'     => 'Stock',
                            'alerts'    => 'Alertas',
                            'evolution' => 'Motor de evolucion',
                            'ai'        => 'Asistente IA',
                            'billing'   => 'Facturacion',
                        ])
                        ->default('general'),

                    Forms\Components\TextInput::make('key')
                        ->label('Clave')
                        ->required()
                        ->maxLength(255)
                        ->unique(Configuration::class, 'key', ignoreRecord: true)
                        ->helperText('snake_case. Ej: stock_minimum_default. No modificar claves del sistema.'),

                    Forms\Components\TextInput::make('value')
                        ->label('Valor')
                        ->nullable()
                        ->maxLength(1000),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripcion')
                        ->nullable()
                        ->maxLength(500)
                        ->rows(2),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group_name')
                    ->label('Grupo')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('key')
                    ->label('Clave')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('value')
                    ->label('Valor')
                    ->searchable()
                    ->placeholder('(nulo)')
                    ->limit(60),

                Tables\Columns\TextColumn::make('description')
                    ->label('Descripcion')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updatedByUser.full_name')
                    ->label('Ultima modificacion por')
                    ->placeholder('Sistema'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group_name')
                    ->label('Grupo')
                    ->options([
                        'general'   => 'General',
                        'stock'     => 'Stock',
                        'alerts'    => 'Alertas',
                        'evolution' => 'Motor de evolucion',
                        'ai'        => 'Asistente IA',
                        'billing'   => 'Facturacion',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('group_name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListConfigurations::route('/'),
            'create' => Pages\CreateConfiguration::route('/create'),
            'edit'   => Pages\EditConfiguration::route('/{record}/edit'),
        ];
    }
}
