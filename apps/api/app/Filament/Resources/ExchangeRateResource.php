<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ExchangeRateResource\Pages;
use App\Models\ExchangeRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

/**
 * Filament Resource for viewing and overriding the daily exchange rate — §16.7.
 *
 * Visibility: Director-only.
 *
 * The table shows the history of daily rates with their source. The create form
 * allows a Director to manually enter a rate for today (or any date) when the
 * BCRA API is unavailable, inserting a row with source='manual_override'.
 *
 * The edit action is intentionally hidden — to modify a rate that was already
 * entered, the Director creates a new row for the same date. The latest row
 * for a date wins in application logic (ExchangeRate uses updateOrCreate keyed
 * on rate_date, so a manual_override can effectively replace an api_bna row).
 */
class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Tipos de cambio';

    protected static ?string $modelLabel = 'Tipo de cambio';

    protected static ?string $pluralModelLabel = 'Tipos de cambio';

    protected static ?string $navigationGroup = 'Configuracion';

    protected static ?int $navigationSort = 11;

    /**
     * Only Directors may access this resource (§16.7).
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tipo de cambio manual')
                ->description('Ingresá el TC dólar vendedor BNA para el día indicado.')
                ->schema([
                    Forms\Components\DatePicker::make('rate_date')
                        ->label('Fecha')
                        ->required()
                        ->default(today())
                        ->displayFormat('d/m/Y'),

                    Forms\Components\TextInput::make('rate_ars_per_usd')
                        ->label('ARS por USD (venta)')
                        ->required()
                        ->numeric()
                        ->minValue(0.000001)
                        ->step(0.000001)
                        ->helperText('Valor del dólar vendedor BNA. Ej: 1050.500000'),

                    Forms\Components\Hidden::make('source')
                        ->default('manual_override'),

                    Forms\Components\Hidden::make('recorded_by')
                        ->default(fn (): ?string => auth()->id()),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rate_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rate_ars_per_usd')
                    ->label('ARS / USD')
                    ->formatStateUsing(fn (string $state): string => number_format((float) $state, 4))
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('source')
                    ->label('Fuente')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'api_bna'         => 'API BNA',
                        'manual_override' => 'Override manual',
                        'fallback'        => 'Fallback',
                        default           => $state,
                    })
                    ->colors([
                        'success' => 'api_bna',
                        'warning' => 'fallback',
                        'danger'  => 'manual_override',
                    ]),

                Tables\Columns\TextColumn::make('recordedBy.full_name')
                    ->label('Cargado por')
                    ->placeholder('Sistema'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('rate_date', 'desc')
            ->headerActions([
                // "Set today TC manually" — Director override for when BCRA API is down.
                // Creates/replaces the rate for today with source='manual_override'.
                Action::make('set_today_tc')
                    ->label('Cargar TC de hoy manualmente')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('rate_ars_per_usd')
                            ->label('ARS por USD (venta)')
                            ->required()
                            ->numeric()
                            ->minValue(0.000001)
                            ->step(0.000001)
                            ->helperText('Valor dolar vendedor BNA del dia. Ej: 1085.500000'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Ingresar TC manual para hoy')
                    ->modalDescription(
                        'Este valor se usara para todas las operaciones del dia mientras la API BCRA no responda. ' .
                        'El cambio queda registrado en el log de auditoria.'
                    )
                    ->action(function (array $data): void {
                        ExchangeRate::updateOrCreate(
                            ['rate_date' => today()->toDateString()],
                            [
                                'rate_ars_per_usd' => $data['rate_ars_per_usd'],
                                'source'           => 'manual_override',
                                'recorded_by'      => auth()->id(),
                            ]
                        );

                        Notification::make()
                            ->success()
                            ->title('TC cargado')
                            ->body('Tipo de cambio manual para hoy registrado exitosamente.')
                            ->send();
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->label('Fuente')
                    ->options([
                        'api_bna'         => 'API BNA',
                        'manual_override' => 'Override manual',
                        'fallback'        => 'Fallback',
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListExchangeRates::route('/'),
            'create' => Pages\CreateExchangeRate::route('/create'),
        ];
    }
}
