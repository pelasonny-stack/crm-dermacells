<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DistributorAccountResource\Pages;
use App\Jobs\DistributorFinance\RecalculateDistributorAccountJob;
use App\Models\DistributorAccount;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament resource — DistributorAccount (§9.3)
 *
 * Read-only widget per Distributor. Directors see all; Distributors see their own.
 * The "Recalculate" action triggers RecalculateDistributorAccountJob.
 * No create/edit forms — accounts are managed entirely by the recalculation job.
 */
class DistributorAccountResource extends Resource
{
    protected static ?string $model = DistributorAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Cuentas corrientes';

    protected static ?string $navigationGroup = 'Distribuidor Financiero';

    protected static ?int $navigationSort = 2;

    /**
     * Director sees all accounts. Distributor sees own account (RLS-scoped).
     */
    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, [
            \App\Enums\UserRole::Director,
            \App\Enums\UserRole::Distributor,
        ], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('distributor.full_name')
                    ->label('Distribuidor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_ars')
                    ->label('Saldo a rendir ARS')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_usd')
                    ->label('Saldo a rendir USD')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('gross_margin_ars')
                    ->label('Margen bruto ARS')
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('gross_margin_usd')
                    ->label('Margen bruto USD')
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('last_recalculated_at')
                    ->label('Último cálculo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('recalculate')
                    ->label('Recalcular')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (DistributorAccount $record): void {
                        RecalculateDistributorAccountJob::dispatch($record->distributor_id);
                        Notification::make()
                            ->success()
                            ->title('Recálculo programado')
                            ->body('El saldo se actualizará en breve.')
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('balance_usd', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Cuenta corriente')->schema([
                Infolists\Components\TextEntry::make('distributor.full_name')
                    ->label('Distribuidor'),

                Infolists\Components\TextEntry::make('balance_ars')
                    ->label('Saldo a rendir ARS')
                    ->numeric(decimalPlaces: 2),

                Infolists\Components\TextEntry::make('balance_usd')
                    ->label('Saldo a rendir USD')
                    ->numeric(decimalPlaces: 2),

                Infolists\Components\TextEntry::make('gross_margin_ars')
                    ->label('Margen bruto ARS')
                    ->numeric(decimalPlaces: 2),

                Infolists\Components\TextEntry::make('gross_margin_usd')
                    ->label('Margen bruto USD')
                    ->numeric(decimalPlaces: 2),

                Infolists\Components\TextEntry::make('last_recalculated_at')
                    ->label('Último recálculo')
                    ->dateTime('d/m/Y H:i'),
            ])->columns(3),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDistributorAccounts::route('/'),
            'view'  => Pages\ViewDistributorAccount::route('/{record}'),
        ];
    }

    /**
     * Disable create and edit — accounts are managed by the job.
     */
    public static function canCreate(): bool
    {
        return false;
    }
}
