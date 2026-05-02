<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerCreditBalanceResource\Pages;
use App\Models\CustomerCreditBalance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament read-only resource for customer credit balances (saldo a favor) — Phase 7.
 *
 * Director-only (panel-level guard). Read-only: credit balances are created
 * automatically by CreditBalanceService, never manually in Filament.
 *
 * Application to sales is done via POST /customers/{id}/credit-balances/{cbId}/apply.
 */
class CustomerCreditBalanceResource extends Resource
{
    protected static ?string $model = CustomerCreditBalance::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Cobranzas';

    protected static ?string $navigationLabel = 'Saldos a favor';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Saldo a favor')->schema([
                Forms\Components\Select::make('customer_id')
                    ->relationship('customer', 'first_name')
                    ->label('Cliente')
                    ->disabled(),

                Forms\Components\TextInput::make('amount_amount')
                    ->label('Monto')
                    ->disabled(),

                Forms\Components\TextInput::make('amount_currency')
                    ->label('Moneda')
                    ->disabled(),

                Forms\Components\Select::make('origin_sale_id')
                    ->relationship('originSale', 'id')
                    ->label('Venta de origen')
                    ->disabled(),

                Forms\Components\TextInput::make('origin_credit_note_id')
                    ->label('Nota de crédito (Fase 6)')
                    ->disabled(),

                Forms\Components\Select::make('applied_to_sale_id')
                    ->relationship('appliedToSale', 'id')
                    ->label('Imputado a venta')
                    ->disabled(),

                Forms\Components\DateTimePicker::make('applied_at')
                    ->label('Fecha de imputación')
                    ->disabled(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.first_name')
                    ->label('Cliente')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount_amount')
                    ->label('Monto'),

                Tables\Columns\TextColumn::make('amount_currency')
                    ->label('Moneda')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'USD' ? 'success' : 'info'),

                Tables\Columns\TextColumn::make('origin_sale_id')
                    ->label('Venta origen')
                    ->limit(10),

                Tables\Columns\TextColumn::make('applied_to_sale_id')
                    ->label('Imputado a')
                    ->limit(10)
                    ->placeholder('Disponible'),

                Tables\Columns\TextColumn::make('applied_at')
                    ->label('Fecha imputación')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('amount_currency')
                    ->label('Moneda')
                    ->options(['ARS' => 'ARS', 'USD' => 'USD']),

                Tables\Filters\Filter::make('unapplied')
                    ->label('Solo disponibles')
                    ->query(fn ($query) => $query->whereNull('applied_to_sale_id')),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerCreditBalances::route('/'),
            'view'  => Pages\ViewCustomerCreditBalance::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }
}
