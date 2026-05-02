<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource for Invoices (§6).
 *
 * Read-mostly: invoices are created via the API which dispatches
 * IssueXubioInvoiceJob. Director can re-emit a failed invoice from the
 * row action (re-dispatches the job which is ShouldBeUnique so re-runs
 * are safe).
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Facturas';

    protected static ?string $navigationGroup = 'Facturacion';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        // Direct creation through Filament is intentionally disabled —
        // invoices are dispatched via API (IssueXubioInvoiceJob).
        return $form->schema([
            Forms\Components\Placeholder::make('info')
                ->content('Las facturas se crean desde la pantalla de Ventas → "Emitir factura".'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->limit(8)
                    ->copyable()
                    ->tooltip(fn (Invoice $record) => $record->id),

                Tables\Columns\TextColumn::make('sale_id')
                    ->label('Venta')
                    ->limit(8)
                    ->copyable(),

                Tables\Columns\TextColumn::make('billingEntity.name')
                    ->label('Razón social'),

                Tables\Columns\TextColumn::make('voucher_type')
                    ->label('Tipo')
                    ->badge(),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Número')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('cae')
                    ->label('CAE')
                    ->placeholder('—')
                    ->limit(14),

                Tables\Columns\TextColumn::make('amount_ars')
                    ->label('Total ARS')
                    ->money('ARS')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emitida')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(InvoiceStatus::cases())->mapWithKeys(
                        fn (InvoiceStatus $s) => [$s->value => $s->label()]
                    )->toArray())
                    ->label('Estado'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('reEmit')
                    ->label('Reintentar emisión')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Invoice $record) => $record->isFailed())
                    ->requiresConfirmation()
                    ->action(function (Invoice $record): void {
                        \App\Jobs\Xubio\IssueXubioInvoiceJob::dispatch($record->id);
                        Notification::make()->success()->title('Reintento agendado')->send();
                    }),

                Tables\Actions\Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Invoice $record) => $record->isSuccess() && $record->pdf_url !== null)
                    ->url(fn (Invoice $record) => route('invoices.pdf', ['invoice' => $record->id]))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['billingEntity', 'sale']);
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
            'index' => Pages\ListInvoices::route('/'),
            'view'  => Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
