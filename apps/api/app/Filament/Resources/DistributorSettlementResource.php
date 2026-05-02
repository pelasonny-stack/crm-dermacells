<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SettlementStatus;
use App\Filament\Resources\DistributorSettlementResource\Pages;
use App\Jobs\DistributorFinance\RecalculateDistributorAccountJob;
use App\Models\DistributorSettlement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament resource — DistributorSettlement (§9.4)
 *
 * Director can view all settlements and confirm/reject pending ones.
 * The "Confirm" and "Reject" actions update status and trigger account recalculation.
 */
class DistributorSettlementResource extends Resource
{
    protected static ?string $model = DistributorSettlement::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Rendiciones';

    protected static ?string $navigationGroup = 'Distribuidor Financiero';

    protected static ?int $navigationSort = 3;

    /**
     * Director approves/rejects. Distributor submits and tracks own settlements.
     */
    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, [
            \App\Enums\UserRole::Director,
            \App\Enums\UserRole::Distributor,
        ], true);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Rendición')->schema([
                Forms\Components\Select::make('distributor_id')
                    ->label('Distribuidor')
                    ->relationship('distributor', 'full_name')
                    ->searchable()
                    ->required(),

                Forms\Components\TextInput::make('amount_amount')
                    ->label('Monto')
                    ->numeric()
                    ->minValue(0.01)
                    ->required(),

                Forms\Components\Select::make('amount_currency')
                    ->label('Moneda')
                    ->options(['ARS' => 'ARS', 'USD' => 'USD'])
                    ->required(),

                Forms\Components\TextInput::make('reference')
                    ->label('Referencia')
                    ->maxLength(500),

                Forms\Components\Textarea::make('notes')
                    ->label('Notas')
                    ->rows(3),
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

                Tables\Columns\TextColumn::make('amount_amount')
                    ->label('Monto')
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('amount_currency')
                    ->label('Moneda'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (SettlementStatus $state) => $state->label())
                    ->color(fn (SettlementStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Enviada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('confirmedBy.full_name')
                    ->label('Confirmada por'),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Confirmada el')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(SettlementStatus::cases())
                        ->mapWithKeys(fn (SettlementStatus $s) => [$s->value => $s->label()])
                        ->toArray()
                    )
                    ->label('Estado'),

                Tables\Filters\SelectFilter::make('distributor_id')
                    ->label('Distribuidor')
                    ->relationship('distributor', 'full_name'),
            ])
            ->actions([
                // ---- Confirm action ----
                Tables\Actions\Action::make('confirm')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DistributorSettlement $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('Notas (opcional)')
                            ->rows(2),
                    ])
                    ->action(function (DistributorSettlement $record, array $data): void {
                        $record->status       = SettlementStatus::Confirmed;
                        $record->confirmed_by = auth()->id();
                        $record->confirmed_at = now();
                        if (! empty($data['notes'])) {
                            $record->notes = $data['notes'];
                        }
                        $record->save();

                        RecalculateDistributorAccountJob::dispatch($record->distributor_id);

                        Notification::make()->success()->title('Rendición confirmada')->send();
                    }),

                // ---- Reject action ----
                Tables\Actions\Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DistributorSettlement $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('Motivo del rechazo')
                            ->required()
                            ->minLength(5)
                            ->rows(2),
                    ])
                    ->action(function (DistributorSettlement $record, array $data): void {
                        $record->status       = SettlementStatus::Rejected;
                        $record->confirmed_by = auth()->id();
                        $record->confirmed_at = now();
                        $record->notes        = $data['notes'];
                        $record->save();

                        Notification::make()->success()->title('Rendición rechazada')->send();
                    }),

                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('submitted_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Detalle de rendición')->schema([
                Infolists\Components\TextEntry::make('distributor.full_name')->label('Distribuidor'),
                Infolists\Components\TextEntry::make('amount_amount')->label('Monto')->numeric(decimalPlaces: 2),
                Infolists\Components\TextEntry::make('amount_currency')->label('Moneda'),
                Infolists\Components\TextEntry::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (SettlementStatus $state) => $state->label())
                    ->color(fn (SettlementStatus $state) => $state->color()),
                Infolists\Components\TextEntry::make('reference')->label('Referencia'),
                Infolists\Components\TextEntry::make('submitted_at')->label('Enviada')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('confirmedBy.full_name')->label('Confirmada por'),
                Infolists\Components\TextEntry::make('confirmed_at')->label('Confirmada el')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('notes')->label('Notas')->columnSpanFull(),
            ])->columns(4),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDistributorSettlements::route('/'),
            'view'   => Pages\ViewDistributorSettlement::route('/{record}'),
        ];
    }

    /**
     * Disable create from Filament (Distributors submit via API/app).
     */
    public static function canCreate(): bool
    {
        return false;
    }
}
