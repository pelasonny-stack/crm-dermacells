<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\CommissionTierResource\Pages;
use App\Models\CommissionTier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

/**
 * Filament Resource for managing the global commission tier scale — §12.2, §16.5.
 *
 * Visibility: Director-only.
 *
 * The commission scale is versioned: adding a new set of tiers with a future
 * effective_from date activates them from that date forward without deleting
 * historical configuration. The CommissionCalculatorService resolves the active
 * tier set by taking the latest effective_from <= target month.
 *
 * rate_pct is stored as a decimal (e.g. 0.1000 = 10%). The form shows it as a
 * percentage (0-100) and converts on save.
 */
class CommissionTierResource extends Resource
{
    protected static ?string $model = CommissionTier::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Escala de comisiones';

    protected static ?string $modelLabel = 'Tier de comision';

    protected static ?string $pluralModelLabel = 'Escala de comisiones';

    protected static ?string $navigationGroup = 'Configuracion';

    protected static ?int $navigationSort = 12;

    /**
     * Only Directors may access this resource (§16.5).
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tier de comision')
                ->schema([
                    Forms\Components\TextInput::make('tier_order')
                        ->label('Orden del tier')
                        ->required()
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->helperText('1 = nivel base, 2 = intermedio, 3 = alto'),

                    Forms\Components\DatePicker::make('effective_from')
                        ->label('Vigente desde')
                        ->required()
                        ->default(today())
                        ->displayFormat('d/m/Y'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Umbral y porcentaje')
                ->schema([
                    Forms\Components\TextInput::make('threshold_amount')
                        ->label('Umbral monto cobrado')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->helperText('Monto mínimo cobrado en el mes (USD equivalente) para activar este tier'),

                    Forms\Components\Select::make('threshold_currency')
                        ->label('Moneda del umbral')
                        ->required()
                        ->options(['USD' => 'USD'])
                        ->default('USD'),

                    Forms\Components\TextInput::make('rate_pct')
                        ->label('Porcentaje de comision (0 a 1)')
                        ->required()
                        ->numeric()
                        ->minValue(0.0001)
                        ->maxValue(1)
                        ->step(0.0001)
                        ->helperText('Ej: 0.10 = 10%, 0.12 = 12%, 0.15 = 15%'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('effective_from')
                    ->label('Vigente desde')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tier_order')
                    ->label('Tier')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('threshold_amount')
                    ->label('Umbral USD')
                    ->formatStateUsing(
                        fn (CommissionTier $record): string =>
                            'USD ' . number_format((float) $record->threshold_amount, 0, ',', '.')
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('rate_pct')
                    ->label('Comision %')
                    ->formatStateUsing(
                        fn (string $state): string => number_format((float) $state * 100, 1) . '%'
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.full_name')
                    ->label('Configurado por')
                    ->placeholder('Sistema'),
            ])
            ->defaultSort('effective_from', 'desc')
            ->defaultSort('tier_order')
            ->actions([
                // "Copy effective_from to today" — quickly makes an existing tier
                // active from today without having to open the edit form.
                Action::make('copy_to_today')
                    ->label('Copiar vigente desde hoy')
                    ->icon('heroicon-o-calendar')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Cambia la fecha "vigente desde" de este tier a hoy. El cambio quedara registrado en el log de auditoria.')
                    ->action(function (CommissionTier $record): void {
                        $record->effective_from = today()->toDateString();
                        $record->save();

                        Notification::make()
                            ->success()
                            ->title('Fecha actualizada')
                            ->body("Tier {$record->tier_order} ahora vigente desde hoy.")
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCommissionTiers::route('/'),
            'create' => Pages\CreateCommissionTier::route('/create'),
            'edit'   => Pages\EditCommissionTier::route('/{record}/edit'),
        ];
    }
}
