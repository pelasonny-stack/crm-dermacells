<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsappThreadResource\Pages\ListWhatsappThreads;
use App\Filament\Resources\WhatsappThreadResource\Pages\ViewWhatsappThread;
use App\Models\WhatsappThread;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource for WhatsApp thread review (Phase 12 — §17).
 *
 * READ-ONLY
 * =========
 * This resource does not expose create/edit/delete operations.
 * Threads are created automatically by IngestWhatsappWebhookJob.
 * The only "write" action available to Directors is linking an
 * UnmatchedWhatsappMessage to a customer (handled in UnmatchedWhatsappMessageResource).
 *
 * MESSAGES DRAWER
 * ===============
 * The view page embeds the last N messages for the thread in a
 * read-only repeater so Directors can review conversation history
 * without leaving the admin panel.
 *
 * ACCESS
 * ======
 * This resource is registered in the Director-only Filament Admin panel.
 * Sellers and Distributors access messages exclusively through the PWA API.
 */
class WhatsappThreadResource extends Resource
{
    protected static ?string $model = WhatsappThread::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Conversaciones WhatsApp';

    protected static ?string $navigationGroup = 'WhatsApp';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'wa_phone';

    // This resource is read-only — disable create.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        // Form schema is intentionally empty — no create/edit path.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('wa_phone')
                    ->label('Teléfono WA')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.first_name')
                    ->label('Cliente')
                    ->formatStateUsing(fn ($state, WhatsappThread $record) => $record->customer
                        ? $record->customer->fullName()
                        : '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('customer', fn (Builder $q) => $q
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%"));
                    }),

                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Último mensaje')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_inbound_at')
                    ->label('Último entrante')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('customer_id')
                    ->label('Vinculado')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('sin_cliente')
                    ->label('Sin cliente vinculado')
                    ->query(fn (Builder $query) => $query->whereNull('customer_id')),
            ])
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
            'index' => ListWhatsappThreads::route('/'),
            'view'  => ViewWhatsappThread::route('/{record}'),
        ];
    }
}
