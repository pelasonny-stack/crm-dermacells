<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappThread;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| RlsWhatsappVisibilityTest — Phase 12
|--------------------------------------------------------------------------
|
| Verifies Postgres RLS enforcement on whatsapp_threads and whatsapp_messages.
|
| Seller A must NOT see threads/messages belonging to Seller B's customers,
| even when querying directly through Eloquent (which uses app_role).
|
| The test mirrors the cross-role denial pattern established in Phase 3's
| RlsCustomerVisibilityTest.
|
*/

/**
 * Set the Postgres GUCs for a given user role within the current transaction.
 */
function setRlsContext(string $userId, string $role): void
{
    DB::statement("SET LOCAL app.user_id = '{$userId}'");
    DB::statement("SET LOCAL app.user_role = '{$role}'");
}

beforeEach(function (): void {
    $this->zone = \App\Models\Zone::factory()->create(['distributor_id' => null]);

    $this->sellerA = User::factory()->create(['role' => UserRole::Seller]);
    $this->sellerB = User::factory()->create(['role' => UserRole::Seller]);

    $this->customerA = Customer::factory()->create([
        'assigned_seller_id' => $this->sellerA->id,
        'zone_id'            => $this->zone->id,
    ]);

    $this->customerB = Customer::factory()->create([
        'assigned_seller_id' => $this->sellerB->id,
        'zone_id'            => $this->zone->id,
    ]);

    // Create one thread + message for each customer (in director scope so
    // INSERT passes WITH CHECK).
    $this->threadA = WhatsappThread::create([
        'customer_id'     => $this->customerA->id,
        'wa_phone'        => '5491111111111',
        'last_message_at' => now(),
        'last_inbound_at' => now(),
    ]);

    $this->threadB = WhatsappThread::create([
        'customer_id'     => $this->customerB->id,
        'wa_phone'        => '5492222222222',
        'last_message_at' => now(),
        'last_inbound_at' => now(),
    ]);

    WhatsappMessage::create([
        'thread_id'     => $this->threadA->id,
        'wa_message_id' => 'wamid.rls-a-1',
        'direction'     => 'inbound',
        'body'          => 'Mensaje cliente A',
        'message_type'  => 'text',
        'sent_at'       => now(),
        'synced_at'     => now(),
    ]);

    WhatsappMessage::create([
        'thread_id'     => $this->threadB->id,
        'wa_message_id' => 'wamid.rls-b-1',
        'direction'     => 'inbound',
        'body'          => 'Mensaje cliente B',
        'message_type'  => 'text',
        'sent_at'       => now(),
        'synced_at'     => now(),
    ]);
});

it('seller only sees threads of their own assigned customers', function (): void {
    DB::transaction(function (): void {
        setRlsContext($this->sellerA->id, 'seller');

        $visible = WhatsappThread::all();

        expect($visible)->toHaveCount(1);
        expect($visible->first()->id)->toBe($this->threadA->id);
        expect($visible->pluck('id'))->not->toContain($this->threadB->id);
    });
});

it('seller cannot see messages from another sellers customer thread', function (): void {
    DB::transaction(function (): void {
        setRlsContext($this->sellerA->id, 'seller');

        // Direct query on whatsapp_messages — RLS filters via thread_id sub-select.
        $visibleMessages = WhatsappMessage::all();

        $messageIds = $visibleMessages->pluck('wa_message_id');
        expect($messageIds)->toContain('wamid.rls-a-1');
        expect($messageIds)->not->toContain('wamid.rls-b-1');
    });
});

it('director sees all threads regardless of assignment', function (): void {
    // setUp() already sets GUCs to director — no override needed.
    $allThreads = WhatsappThread::all();

    expect($allThreads)->toHaveCount(2);
});

it('seller B does not see seller As thread via API endpoint', function (): void {
    $response = $this->actingAs($this->sellerB, 'sanctum')
        ->getJson("/api/v1/customers/{$this->customerA->id}/whatsapp/threads");

    // RLS on customers prevents sellerB from seeing customerA at all,
    // so route model binding returns 404 or empty data.
    // Either 404 (if customer not visible) or 200 with empty data is acceptable.
    expect(in_array($response->status(), [200, 404]))->toBeTrue();

    if ($response->status() === 200) {
        expect($response->json('data'))->toBeEmpty();
    }
});
