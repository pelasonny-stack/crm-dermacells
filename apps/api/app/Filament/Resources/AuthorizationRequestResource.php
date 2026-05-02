<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Authorizations\Services\AuthorizationService;
use App\Enums\AuthorizationType;
use App\Filament\Resources\AuthorizationRequestResource\Pages;
use App\Models\AuthorizationRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource for Phase 11 authorization requests (§16.12).
 *
 * Features:
 * - Default view is the Pending Queue page — Director sees all unresolved
 *   requests in chronological order.
 * - Inline Approve action (single click, no modal).
 * - Inline Reject action with a modal requiring a rejection_reason.
 * - Filtering by type (price_change / exchange_rate_change) and date range.
 * - Director-only access — enforced by canAccess() and the Filament panel guard.
 */
class AuthorizationRequestResource extends Resource
{
    protected static ?string $model = AuthorizationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Autorizaciones';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'id';

    // -------------------------------------------------------------------------
    // Form (used for Filament's show/view page; Director cannot edit directly)
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Solicitud')->schema([
                Forms\Components\Select::make('type')
                    ->label('Tipo')
                    ->options(collect(AuthorizationType::cases())->mapWithKeys(
                        fn (AuthorizationType $t) => [$t->value => $t->label()]
                    ))
                    ->disabled(),

                Forms\Components\TextInput::make('current_value')
                    ->label('Valor actual')
                    ->disabled(),

                Forms\Components\TextInput::make('proposed_value')
                    ->label('Valor propuesto')
                    ->disabled(),

                Forms\Components\TextInput::make('value_currency')
                    ->label('Moneda')
                    ->disabled(),

                Forms\Components\Textarea::make('reason')
                    ->label('Motivo')
                    ->disabled()
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Resolución')->schema([
                Forms\Components\Select::make('status')
                    ->label('Estado')
                    ->options([
                        'pending'  => 'Pendiente',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                    ])
                    ->disabled(),

                Forms\Components\TextInput::make('resolved_by')
                    ->label('Resuelto por')
                    ->disabled(),

                Forms\Components\DateTimePicker::make('resolved_at')
                    ->label('Fecha de resolución')
                    ->disabled(),

                Forms\Components\Textarea::make('rejection_reason')
                    ->label('Motivo de rechazo')
                    ->disabled()
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requester', 'sale']))
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (AuthorizationType $state) => $state->label())
                    ->badge()
                    ->color(fn (AuthorizationType $state) => match ($state) {
                        AuthorizationType::PriceChange       => 'warning',
                        AuthorizationType::ExchangeRateChange => 'info',
                    }),

                Tables\Columns\TextColumn::make('requester.full_name')
                    ->label('Solicitante')
                    ->searchable(),

                Tables\Columns\TextColumn::make('current_value')
                    ->label('Valor actual')
                    ->numeric(4),

                Tables\Columns\TextColumn::make('proposed_value')
                    ->label('Valor propuesto')
                    ->numeric(4),

                Tables\Columns\TextColumn::make('value_currency')
                    ->label('Moneda')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending'  => 'Pendiente',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Enviada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pending'  => 'Pendiente',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(collect(AuthorizationType::cases())->mapWithKeys(
                        fn (AuthorizationType $t) => [$t->value => $t->label()]
                    )),
            ])
            ->actions([
                // Approve — single click, no modal needed
                Tables\Actions\Action::make('approve')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AuthorizationRequest $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar aprobación')
                    ->modalDescription('¿Aprobar esta solicitud? El valor propuesto quedará disponible para esa operación.')
                    ->action(function (AuthorizationRequest $record, AuthorizationService $service): void {
                        $service->approve($record, auth()->user());
                        Notification::make()->title('Solicitud aprobada')->success()->send();
                    }),

                // Reject — modal requires rejection_reason
                Tables\Actions\Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AuthorizationRequest $record) => $record->isPending())
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Motivo del rechazo')
                            ->required()
                            ->minLength(5)
                            ->maxLength(2000),
                    ])
                    ->action(function (AuthorizationRequest $record, array $data, AuthorizationService $service): void {
                        $service->reject($record, auth()->user(), $data['rejection_reason']);
                        Notification::make()->title('Solicitud rechazada')->danger()->send();
                    }),

                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index'     => Pages\ListAuthorizationRequests::route('/'),
            'pending'   => Pages\PendingAuthorizationQueue::route('/pending'),
            'view'      => Pages\ViewAuthorizationRequest::route('/{record}'),
        ];
    }

    /**
     * Navigation badge: count of pending requests.
     * Displayed on the sidebar link so Directors spot new requests instantly.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = AuthorizationRequest::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
