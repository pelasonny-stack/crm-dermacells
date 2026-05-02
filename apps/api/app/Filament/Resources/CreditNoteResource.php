<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CreditNoteStatus;
use App\Enums\UserRole;
use App\Filament\Resources\CreditNoteResource\Pages;
use App\Models\CreditNote;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationLabel = 'Notas de crédito';

    protected static ?string $navigationGroup = 'Facturacion';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->limit(8)->copyable(),
                Tables\Columns\TextColumn::make('invoice_id')->label('Factura')->limit(8)->copyable(),
                Tables\Columns\TextColumn::make('cn_number')->label('Número')->placeholder('—'),
                Tables\Columns\TextColumn::make('cae')->label('CAE')->placeholder('—')->limit(14),
                Tables\Columns\TextColumn::make('amount_ars')->label('Monto ARS')->money('ARS'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (CreditNoteStatus $s) => $s->label())
                    ->color(fn (CreditNoteStatus $s) => $s->color()),
                Tables\Columns\TextColumn::make('issued_at')->label('Emitida')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(CreditNoteStatus::cases())->mapWithKeys(
                        fn (CreditNoteStatus $s) => [$s->value => $s->label()]
                    )->toArray()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['invoice', 'sale']);
        $user  = auth()->user();

        if (! $user) {
            return $query->whereRaw('1=0');
        }

        /** @var \App\Models\User $user */
        return match ($user->role) {
            UserRole::Director    => $query,
            UserRole::Distributor => $query->whereHas('sale.zone', fn (Builder $q) =>
                $q->where('distributor_id', $user->id)
            ),
            UserRole::Seller      => $query->whereHas('sale', fn (Builder $q) =>
                $q->where('seller_id', $user->id)
            ),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreditNotes::route('/'),
        ];
    }
}
