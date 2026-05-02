<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\UnmatchedWhatsappMessage;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappThread;
use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| EncryptedBodyAtRestTest — Phase 12
|--------------------------------------------------------------------------
|
| Verifies that message bodies are encrypted on disk but returned as
| plaintext through the Eloquent model.
|
| Two tables are checked:
|   - whatsapp_messages.body
|   - unmatched_whatsapp_messages.body
|
*/

beforeEach(function (): void {
    $seller   = User::factory()->create(['role' => UserRole::Seller]);
    $zone     = \App\Models\Zone::factory()->create(['distributor_id' => null]);
    $customer = Customer::factory()->create([
        'assigned_seller_id' => $seller->id,
        'zone_id'            => $zone->id,
    ]);

    $this->thread = WhatsappThread::create([
        'customer_id'    => $customer->id,
        'wa_phone'       => '5491199999999',
        'last_message_at' => now(),
        'last_inbound_at' => now(),
    ]);
});

it('stores whatsapp_messages.body as ciphertext and decrypts via Eloquent', function (): void {
    $plaintext = 'Este es un mensaje secreto de un cliente';

    WhatsappMessage::create([
        'thread_id'     => $this->thread->id,
        'wa_message_id' => 'wamid.encrypt-test-1',
        'direction'     => 'inbound',
        'body'          => $plaintext,
        'message_type'  => 'text',
        'sent_at'       => now(),
        'synced_at'     => now(),
    ]);

    // Direct DB query — raw column value must NOT equal plaintext.
    $rawBody = DB::table('whatsapp_messages')
        ->where('wa_message_id', 'wamid.encrypt-test-1')
        ->value('body');

    expect($rawBody)->not->toBe($plaintext);
    // Ciphertext should be a base64-like string (Laravel Crypt::encrypt output).
    expect($rawBody)->toBeString()->not->toBeEmpty();

    // Eloquent access — decrypted transparently.
    $model = WhatsappMessage::where('wa_message_id', 'wamid.encrypt-test-1')->firstOrFail();
    expect($model->body)->toBe($plaintext);
});

it('stores unmatched_whatsapp_messages.body as ciphertext and decrypts via Eloquent', function (): void {
    $plaintext = 'Mensaje sin cliente también cifrado';

    UnmatchedWhatsappMessage::create([
        'wa_phone'      => '5400000000099',
        'body'          => $plaintext,
        'wa_message_id' => 'wamid.unmatched-encrypt-1',
        'message_type'  => 'text',
        'received_at'   => now(),
    ]);

    $rawBody = DB::table('unmatched_whatsapp_messages')
        ->where('wa_message_id', 'wamid.unmatched-encrypt-1')
        ->value('body');

    expect($rawBody)->not->toBe($plaintext);
    expect($rawBody)->toBeString()->not->toBeEmpty();

    $model = UnmatchedWhatsappMessage::where('wa_message_id', 'wamid.unmatched-encrypt-1')->firstOrFail();
    expect($model->body)->toBe($plaintext);
});
