<?php

declare(strict_types=1);

use App\Jobs\Whatsapp\IngestWhatsappWebhookJob;
use App\Models\UnmatchedWhatsappMessage;
use App\Models\WhatsappThread;

/*
|--------------------------------------------------------------------------
| IngestUnmatchedTest — Phase 12
|--------------------------------------------------------------------------
|
| When a message arrives from a phone that matches NO customer, it must:
|   1. NOT create a WhatsappThread.
|   2. Insert one row into unmatched_whatsapp_messages.
|   3. The body must be stored (encrypted at rest — tested separately).
|
*/

it('stores message in unmatched table when phone has no customer match', function (): void {
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id'        => 'wamid.unmatched-001',
                        'from'      => '5499887766554',  // no customer has this phone
                        'timestamp' => (string) now()->timestamp,
                        'type'      => 'text',
                        'text'      => ['body' => 'Mensaje de número desconocido'],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];

    (new IngestWhatsappWebhookJob($payload))->handle();

    // No thread created.
    expect(WhatsappThread::count())->toBe(0);

    // Unmatched row created.
    $unmatched = UnmatchedWhatsappMessage::where('wa_message_id', 'wamid.unmatched-001')->first();
    expect($unmatched)->not->toBeNull();
    expect($unmatched->wa_phone)->toBe('5499887766554');
    expect($unmatched->body)->toBe('Mensaje de número desconocido');
    expect($unmatched->message_type)->toBe('text');
    expect($unmatched->reviewed_at)->toBeNull();
});

it('silently skips a duplicate wa_message_id for unmatched messages', function (): void {
    $makePayload = fn () => [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id'        => 'wamid.unmatched-dedup',
                        'from'      => '5400000000001',
                        'timestamp' => (string) now()->timestamp,
                        'type'      => 'text',
                        'text'      => ['body' => 'Dedup test'],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];

    (new IngestWhatsappWebhookJob($makePayload()))->handle();
    (new IngestWhatsappWebhookJob($makePayload()))->handle();

    // Still one row, not two.
    $count = UnmatchedWhatsappMessage::where('wa_message_id', 'wamid.unmatched-dedup')->count();
    expect($count)->toBe(1);
});
