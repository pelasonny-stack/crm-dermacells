<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UnmatchedWhatsappMessageResource\Pages\ListUnmatchedWhatsappMessages;
use App\Models\Customer;
use App\Models\UnmatchedWhatsappMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappThread;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Filament triage queue for unmatched WhatsApp messages (Phase 12 — §17).
 *
 * DIRECTOR-ONLY
 * =============
 * This resource is only accessible to Directors (enforced by the AdminAccessGate
 * middleware on the Filament panel). Sellers and Distributors never see this.
 *
 * TRIAGE ACTIONS
 * ==============
 * "Assign to customer" table action:
 *   - Prompts the Director to select a Customer from a searchable select.
 *   - On confirm:
 *     1. Upserts a WhatsappThread for the customer + wa_phone.
 *     2. Migrates this message into whatsapp_messages.
 *     3. Marks this row as reviewed (reviewed_by + reviewed_at).
 *   This is wrapped in a DB transaction for atomicity.
 *
 * "Mark reviewed" action:
 *   - Sets reviewed_by + reviewed_at without assigning to a customer
 *     (used for spam / wrong-number messages).
 */
class UnmatchedWhatsappMessageResource extends Resource
{
    protected static ?string $model = UnmatchedWhatsappMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Mensajes sin cliente';

    protected static ?string $navigationGroup = 'WhatsApp';

    protected static ?int $navigationSort = 2;

    // Show a badge with the count of unreviewed messages.
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::whereNull('reviewed_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('wa_phone')
                    ->label('Teléfono')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Mensaje')
                    ->limit(80)
                    ->tooltip(fn (UnmatchedWhatsappMessage $record) => $record->body),

                Tables\Columns\TextColumn::make('message_type')
                    ->label('Tipo')
                    ->badge(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label('Recibido')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\IconColumn::make('reviewed_at')
                    ->label('Revisado')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock'),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('pendientes')
                    ->label('Solo pendientes')
                    ->default()
                    ->query(fn (Builder $query) => $query->whereNull('reviewed_at')),
            ])
            ->actions([
                // Primary triage action: link to a customer.
                Tables\Actions\Action::make('assign_to_customer')
                    ->label('Asignar a cliente')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->visible(fn (UnmatchedWhatsappMessage $record) => ! $record->isReviewed())
                    ->form([
                        \Filament\Forms\Components\Select::make('customer_id')
                            ->label('Cliente')
                            ->options(fn () => Customer::active()
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn (Customer $c) => [
                                    $c->id => $c->fullName() . ' (' . $c->phone . ')',
                                ]))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (UnmatchedWhatsappMessage $record, array $data): void {
                        /** @var Customer $customer */
                        $customer = Customer::findOrFail($data['customer_id']);

                        DB::transaction(function () use ($record, $customer): void {
                            // Upsert the thread.
                            $thread = WhatsappThread::firstOrCreate(
                                ['wa_phone' => $record->wa_phone],
                                ['customer_id' => $customer->id],
                            );

                            if ($thread->customer_id === null) {
                                $thread->customer_id = $customer->id;
                                $thread->save();
                            }

                            // Migrate message into whatsapp_messages.
                            try {
                                WhatsappMessage::create([
                                    'thread_id'     => $thread->id,
                                    'wa_message_id' => $record->wa_message_id,
                                    'direction'     => 'inbound',
                                    'body'          => $record->body,
                                    'message_type'  => $record->message_type,
                                    'sent_at'       => $record->received_at,
                                    'synced_at'     => now(),
                                ]);
                            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                                // Message already migrated — idempotent.
                            }

                            // Mark as reviewed.
                            $record->reviewed_by = auth()->id();
                            $record->reviewed_at = now();
                            $record->save();
                        });

                        Notification::make()
                            ->title('Mensaje asignado a ' . $customer->fullName())
                            ->success()
                            ->send();
                    }),

                // Secondary action: dismiss without linking.
                Tables\Actions\Action::make('mark_reviewed')
                    ->label('Marcar revisado')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (UnmatchedWhatsappMessage $record) => ! $record->isReviewed())
                    ->requiresConfirmation()
                    ->action(function (UnmatchedWhatsappMessage $record): void {
                        $record->reviewed_by = auth()->id();
                        $record->reviewed_at = now();
                        $record->save();

                        Notification::make()
                            ->title('Marcado como revisado')
                            ->success()
                            ->send();
                    }),
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
            'index' => ListUnmatchedWhatsappMessages::route('/'),
        ];
    }
}
