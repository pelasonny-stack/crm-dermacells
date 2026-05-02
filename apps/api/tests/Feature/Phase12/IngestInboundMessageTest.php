<?php

declare(strict_types=1);

use App\Jobs\Whatsapp\IngestWhatsappWebhookJob;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappThread;
use App\Enums\UserRole;

/*
|--------------------------------------------------------------------------
| IngestInboundMessageTest — Phase 12
|--------------------------------------------------------------------------
|
| When IngestWhatsappWebhookJob receives a payload whose `from` phone matches
| an existing customer.phone, it must:
|   1. Create (or reuse) a WhatsappThread for that customer + phone.
|   2. Insert one WhatsappMessage with direction='inbound'.
|   3. Update the thread's last_message_at and last_inbound_at.
|
*/

beforeEach(function (): void {
    $this->seller = User::factory()->create(['role' => UserRole::Seller]);
    $zone         = \App\Models\Zone::factory()->create(['distributor_id' => null]);

    $this->customer = Customer::factory()->create([
        'phone'              => '+54 9 11 1234-5678',
        'assigned_seller_id' => $this->seller->id,
        'zone_id'            => $zone->id,
    ]);
});

/**
 * Build a minimal Meta webhook payload for one inbound text message.
 */
function buildMetaPayload(string $from, string $messageId, string $body, int $timestamp = 0): array
{
    $timestamp = $timestamp ?: now()->timestamp;

    return [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id'        => $messageId,
                        'from'      => $from,
                        'timestamp' => (string) $timestamp,
                        'type'      => 'text',
                        'text'      => ['body' => $body],
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];
}

it('creates a thread and persists the message for a matched customer phone', function (): void {
    $payload = buildMetaPayload(
        from:      '5491112345678',  // normalised form of +54 9 11 1234-5678
        messageId: 'wamid.HBgLNTQ5MTEtdGVzdAABEAAA',
        body:      'Hola, quiero consultar sobre el producto',
    );

    (new IngestWhatsappWebhookJob($payload))->handle();

    // Thread created and linked to customer.
    $thread = WhatsappThread::where('customer_id', $this->customer->id)->first();
    expect($thread)->not->toBeNull();
    expect($thread->wa_phone)->toBe('5491112345678');
    expect($thread->last_message_at)->not->toBeNull();
    expect($thread->last_inbound_at)->not->toBeNull();
    expect($thread->last_outbound_at)->toBeNull();

    // Message persisted.
    $message = WhatsappMessage::where('thread_id', $thread->id)->first();
    expect($message)->not->toBeNull();
    expect($message->direction)->toBe('inbound');
    expect($message->wa_message_id)->toBe('wamid.HBgLNTQ5MTEtdGVzdAABEAAA');
    expect($message->body)->toBe('Hola, quiero consultar sobre el producto');
    expect($message->message_type)->toBe('text');
});

it('reuses an existing thread on a second message from the same phone', function (): void {
    $payload1 = buildMetaPayload('5491112345678', 'wamid.msg1', 'Primer mensaje');
    $payload2 = buildMetaPayload('5491112345678', 'wamid.msg2', 'Segundo mensaje');

    (new IngestWhatsappWebhookJob($payload1))->handle();
    (new IngestWhatsappWebhookJob($payload2))->handle();

    // Still one thread.
    $threadCount = WhatsappThread::where('customer_id', $this->customer->id)->count();
    expect($threadCount)->toBe(1);

    // Two messages.
    $thread        = WhatsappThread::where('customer_id', $this->customer->id)->first();
    $messageCount  = WhatsappMessage::where('thread_id', $thread->id)->count();
    expect($messageCount)->toBe(2);
});

it('matches customer phone regardless of formatting differences', function (): void {
    // Customer stored with formatting; Meta delivers without any.
    $payload = buildMetaPayload('5491112345678', 'wamid.format-test', 'Test');

    (new IngestWhatsappWebhookJob($payload))->handle();

    $thread = WhatsappThread::where('customer_id', $this->customer->id)->first();
    expect($thread)->not->toBeNull();
});
