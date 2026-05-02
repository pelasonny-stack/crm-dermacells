<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource for viewing payments — Phase 7.
 *
 * Director-only (enforced by canAccessPanel → Director check at panel level).
 * Payments are registered via API. This Filament resource is read-only for
 * audit and monitoring purposes; creation happens through the API + RegisterPaymentAction.
 *
 * Reversal (devolución de cobros) can be initiated from the table action
 * which delegates to ReversePaymentAction.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Cobranzas';

    protected static ?string $navigationLabel = 'Cobros';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información del cobro')->schema([
                Forms\Components\Select::make('sale_id')
                    ->relationship('sale', 'id')
                    ->label('Venta')
                    ->searchable()
                    ->disabled(),

                Forms\Components\Select::make('customer_id')
                    ->relationship('customer', 'first_name')
                    ->label('Cliente')
                    ->disabled(),

                Forms\Components\Select::make('payment_method_id')
                    ->relationship('paymentMethod', 'name')
                    ->label('Medio de pago')
                    ->disabled(),

                Forms\Components\TextInput::make('amount_amount')
                    ->label('Monto')
                    ->disabled(),

                Forms\Components\TextInput::make('amount_currency')
                    ->label('Moneda')
                    ->disabled(),

                Forms\Components\DatePicker::make('payment_date')
                    ->label('Fecha de cobro')
                    ->disabled(),

                Forms\Components\Toggle::make('is_advance')
                    ->label('Anticipo')
                    ->disabled(),

                Forms\Components\TextInput::make('cash_destination')
                    ->label('Destino efectivo')
                    ->disabled(),

                Forms\Components\Toggle::make('reversed')
                    ->label('Revertido')
                    ->disabled(),

                Forms\Components\Textarea::make('reversal_reason')
                    ->label('Motivo de reversión')
                    ->disabled(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Fecha')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.first_name')
                    ->label('Cliente')
                    ->searchable(),

                Tables\Columns\TextColumn::make('paymentMethod.name')
                    ->label('Medio'),

                Tables\Columns\TextColumn::make('amount_amount')
                    ->label('Monto')
                    ->money(),

                Tables\Columns\TextColumn::make('amount_currency')
                    ->label('Moneda'),

                Tables\Columns\TextColumn::make('cash_destination')
                    ->label('Destino efectivo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'dermacells'   => 'info',
                        'distributor'  => 'warning',
                        default        => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_advance')
                    ->label('Anticipo')
                    ->boolean(),

                Tables\Columns\IconColumn::make('reversed')
                    ->label('Revertido')
                    ->boolean(),

                Tables\Columns\TextColumn::make('recorder.full_name')
                    ->label('Registrado por')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('amount_currency')
                    ->label('Moneda')
                    ->options(['ARS' => 'ARS', 'USD' => 'USD']),

                Tables\Filters\TernaryFilter::make('reversed')
                    ->label('Revertidos'),

                Tables\Filters\TernaryFilter::make('is_advance')
                    ->label('Solo anticipos'),
            ])
            ->defaultSort('payment_date', 'desc')
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
            'index' => Pages\ListPayments::route('/'),
            'view'  => Pages\ViewPayment::route('/{record}'),
        ];
    }

    /** Payments are read-only in Filament — creation goes through the API. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }
}
