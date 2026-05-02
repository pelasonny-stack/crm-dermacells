<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsappThreadResource\Pages;

use App\Filament\Resources\WhatsappThreadResource;
use App\Models\WhatsappMessage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only view of a WhatsApp thread with embedded message history (Phase 12).
 *
 * The message history loads the 50 most recent messages (newest first) from
 * the thread and renders them in a scrollable repeater. Pagination within
 * the Filament panel is intentionally limited to keep the page fast;
 * the API endpoints handle proper cursor pagination for the PWA.
 *
 * Body values are decrypted transparently via the WhatsappMessage `encrypted`
 * cast before being displayed — the Director sees plaintext, the DB stores
 * ciphertext.
 */
class ViewWhatsappThread extends ViewRecord
{
    protected static string $resource = WhatsappThreadResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Datos del hilo')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('wa_phone')
                            ->label('Teléfono WhatsApp'),

                        TextEntry::make('wa_contact_id')
                            ->label('Contact ID Meta')
                            ->placeholder('—'),

                        TextEntry::make('customer.first_name')
                            ->label('Cliente vinculado')
                            ->formatStateUsing(fn ($state, $record) => $record->customer
                                ? $record->customer->fullName()
                                : 'Sin cliente vinculado'),

                        TextEntry::make('last_message_at')
                            ->label('Último mensaje')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('last_inbound_at')
                            ->label('Último entrante')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('last_outbound_at')
                            ->label('Último saliente')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),

                Section::make('Historial de mensajes (últimos 50)')
                    ->schema([
                        RepeatableEntry::make('recentMessages')
                            ->label('')
                            ->schema([
                                TextEntry::make('direction')
                                    ->label('Dirección')
                                    ->badge()
                                    ->color(fn (string $state) => match ($state) {
                                        'inbound'  => 'success',
                                        'outbound' => 'info',
                                        default    => 'gray',
                                    }),

                                TextEntry::make('sent_at')
                                    ->label('Enviado')
                                    ->dateTime('d/m/Y H:i'),

                                TextEntry::make('body')
                                    ->label('Mensaje')
                                    ->columnSpanFull(),

                                TextEntry::make('message_type')
                                    ->label('Tipo')
                                    ->badge(),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    /**
     * Inject the 50 most recent messages as a virtual attribute on the record
     * so the Infolist RepeatableEntry can iterate over them.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $thread = $this->getRecord();

        $data['recentMessages'] = WhatsappMessage::where('thread_id', $thread->id)
            ->orderByDesc('sent_at')
            ->limit(50)
            ->get()
            ->map(fn (WhatsappMessage $m) => [
                'direction'    => $m->direction,
                'sent_at'      => $m->sent_at,
                'body'         => $m->body,
                'message_type' => $m->message_type,
            ])
            ->toArray();

        return $data;
    }
}
