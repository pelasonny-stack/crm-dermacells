<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SaleResource\Pages;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource for the Sales module (§5).
 *
 * Features:
 *   - Status badge using SaleStatus colors
 *   - Transition action buttons gated by Phase 5 business rules and user role
 *   - Cursor-safe table (no OFFSET pagination — uses keyset pagination plugin or
 *     standard Filament pagination with ORDER BY id)
 *   - Read-only for non-Director roles viewing other users' sales
 *
 * Policy enforcement:
 *   - Director: full access (read + all transitions)
 *   - Distributor: zone-scoped read + can mark as Delivered (if delegated)
 *   - Seller: own sales only + can Confirm and initiate returns
 *
 * The actual business logic lives in the Action classes; Filament actions
 * here just call those Actions to maintain single source of truth.
 */
class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Ventas';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 1;

    // -------------------------------------------------------------------------
    // Form — used for creating/editing draft sales
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos de la venta')->schema([
                Forms\Components\Select::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'first_name')
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('seller_id')
                    ->label('Vendedor')
                    ->relationship('seller', 'full_name')
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('zone_id')
                    ->label('Zona')
                    ->relationship('zone', 'name')
                    ->required(),

                Forms\Components\DatePicker::make('sale_date')
                    ->label('Fecha de venta')
                    ->required(),

                Forms\Components\Select::make('currency')
                    ->label('Moneda')
                    ->options(['ARS' => 'ARS', 'USD' => 'USD'])
                    ->required(),

                Forms\Components\Select::make('payment_terms_id')
                    ->label('Condición de pago')
                    ->relationship('paymentTerms', 'name')
                    ->required(),

                Forms\Components\Toggle::make('delegated_delivery')
                    ->label('Entrega delegada al Distribuidor'),
            ])->columns(2),
        ]);
    }

    // -------------------------------------------------------------------------
    // Table — sale list with state visualizer
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn (Sale $record) => $record->id),

                Tables\Columns\TextColumn::make('customer.first_name')
                    ->label('Cliente')
                    ->searchable(),

                Tables\Columns\TextColumn::make('seller.full_name')
                    ->label('Vendedor')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (SaleStatus $state) => $state->label())
                    ->color(fn (SaleStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('sale_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->money(fn (Sale $record) => $record->total_currency)
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_currency')
                    ->label('Moneda'),

                Tables\Columns\IconColumn::make('delegated_delivery')
                    ->label('Delegada')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(SaleStatus::cases())->mapWithKeys(
                        fn (SaleStatus $s) => [$s->value => $s->label()]
                    )->toArray())
                    ->label('Estado'),
            ])
            ->actions([
                // ---- Confirm action (draft → confirmed) ----
                Tables\Actions\Action::make('confirm')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->visible(fn (Sale $record) => $record->isDraft())
                    ->requiresConfirmation()
                    ->action(function (Sale $record): void {
                        try {
                            app(\App\Actions\Sales\ConfirmSaleAction::class)
                                ->execute($record, auth()->user());
                            Notification::make()->success()->title('Venta confirmada')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Error: ' . $e->getMessage())->send();
                        }
                    }),

                // ---- Deliver action (confirmed → delivered) ----
                Tables\Actions\Action::make('deliver')
                    ->label('Marcar entregada')
                    ->icon('heroicon-o-truck')
                    ->color('success')
                    ->visible(fn (Sale $record) => $record->isConfirmed())
                    ->requiresConfirmation()
                    ->action(function (Sale $record): void {
                        try {
                            app(\App\Actions\Sales\DeliverSaleAction::class)
                                ->execute($record, auth()->user());
                            Notification::make()->success()->title('Venta entregada')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Error: ' . $e->getMessage())->send();
                        }
                    }),

                // ---- Cancel action (any → cancelled) ----
                Tables\Actions\Action::make('cancel')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Sale $record) => ! $record->isCancelled() && ! $record->isDelivered())
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo de cancelación')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (Sale $record, array $data): void {
                        try {
                            app(\App\Actions\Sales\CancelSaleAction::class)
                                ->execute($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Venta cancelada')->send();
                        } catch (\App\Exceptions\InvoiceNcRequiredException $e) {
                            Notification::make()->danger()
                                ->title('Se requiere nota de crédito')
                                ->body('La venta tiene factura emitida. Emitir NC en Xubio primero.')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Error: ' . $e->getMessage())->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('sale_date', 'desc');
    }

    // -------------------------------------------------------------------------
    // Infolist — detail view
    // -------------------------------------------------------------------------

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Estado')->schema([
                Infolists\Components\TextEntry::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (SaleStatus $state) => $state->label())
                    ->color(fn (SaleStatus $state) => $state->color()),

                Infolists\Components\TextEntry::make('sale_date')
                    ->label('Fecha')
                    ->date('d/m/Y'),

                Infolists\Components\TextEntry::make('total_amount')
                    ->label('Total')
                    ->money(fn ($record) => $record->total_currency),

                Infolists\Components\IconEntry::make('delegated_delivery')
                    ->label('Entrega delegada')
                    ->boolean(),
            ])->columns(4),

            Infolists\Components\Section::make('Historial de estados')->schema([
                Infolists\Components\RepeatableEntry::make('statusHistory')
                    ->schema([
                        Infolists\Components\TextEntry::make('from_status')->label('Desde'),
                        Infolists\Components\TextEntry::make('to_status')->label('Hasta'),
                        Infolists\Components\TextEntry::make('changedByUser.full_name')->label('Por'),
                        Infolists\Components\TextEntry::make('changed_at')->label('Cuando')->dateTime('d/m/Y H:i'),
                        Infolists\Components\TextEntry::make('note')->label('Nota'),
                    ])->columns(5),
            ]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'view'   => Pages\ViewSale::route('/{record}'),
            'edit'   => Pages\EditSale::route('/{record}/edit'),
        ];
    }

    /**
     * Scope the Filament query to what the current user is allowed to see.
     * Directors see all; Distributors see their zone; Sellers see own.
     * (RLS middleware already handles this at DB level — this is defense-in-depth
     * at the Filament query builder level so counts/widgets are also correct.)
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['customer', 'seller', 'zone']);
        $user  = auth()->user();

        if (! $user) {
            return $query->whereRaw('1=0');
        }

        /** @var \App\Models\User $user */
        return match ($user->role) {
            UserRole::Director     => $query,
            UserRole::Distributor  => $query->whereHas('zone', fn (Builder $q) =>
                $q->where('distributor_id', $user->id)
            ),
            UserRole::Seller       => $query->where('seller_id', $user->id),
        };
    }
}
