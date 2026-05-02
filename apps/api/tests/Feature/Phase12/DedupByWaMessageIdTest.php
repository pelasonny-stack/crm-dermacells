<?php

declare(strict_types=1);

use App\Jobs\Whatsapp\IngestWhatsappWebhookJob;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Enums\UserRole;

/*
|--------------------------------------------------------------------------
| DedupByWaMessageIdTest — Phase 12
|--------------------------------------------------------------------------
|
| Meta Cloud API may re-deliver the same webhook event (e.g. after a
| non-200 response or transient network issue). The UNIQUE constraint on
| whatsapp_messages.wa_message_id must prevent duplicate rows.
|
| The job must be idempotent: running it twice with the same payload must
| result in exactly ONE whatsapp_messages row.
|
*/

beforeEach(function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone     = \App\Models\Zone::factory()->create(['distributor_id' => null]);

    $this->customer = Customer::factory()->create([
        'phone'              => '5491155556666',
        'assigned_seller_id' => $seller->id,
        'zone_id'            => $zone->id,
    ]);
});

it('inserts only one row when the same wa_message_id is delivered twice', function (): void {
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id'        => 'wamid.DEDUP-UNIQUE-ID',
                        'from'      => '5491155556666',
                        'timestamp' => (string) now()->timestamp,
                        'type'      => 'text',
                        'text'      => ['body' => 'Mensaje que llega dos veces'],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];

    // First delivery.
    (new IngestWhatsappWebhookJob($payload))->handle();

    // Second delivery (simulating Meta retry).
    (new IngestWhatsappWebhookJob($payload))->handle();

    $count = WhatsappMessage::where('wa_message_id', 'wamid.DEDUP-UNIQUE-ID')->count();

    expect($count)->toBe(1);
});

it('inserts multiple rows for different wa_message_ids from the same phone', function (): void {
    $makePayload = fn (string $msgId, string $body) => [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id'        => $msgId,
                        'from'      => '5491155556666',
                        'timestamp' => (string) now()->timestamp,
                        'type'      => 'text',
                        'text'      => ['body' => $body],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];

    (new IngestWhatsappWebhookJob($makePayload('wamid.A', 'Primer mensaje')))->handle();
    (new IngestWhatsappWebhookJob($makePayload('wamid.B', 'Segundo mensaje')))->handle();

    $count = WhatsappMessage::whereIn('wa_message_id', ['wamid.A', 'wamid.B'])->count();
    expect($count)->toBe(2);
});
