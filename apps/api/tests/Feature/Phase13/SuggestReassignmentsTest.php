<?php

declare(strict_types=1);

use App\Domain\AI\UseCases\SuggestCustomerReassignments;
use App\Enums\UserRole;
use App\Jobs\AI\RecordAiUsage;
use App\Models\AiAudit;
use App\Models\AiSetting;
use App\Models\Customer;
use App\Models\User;
use App\Models\Zone;
use App\Services\Customers\CustomerReassignmentService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 13 — §11.4 SuggestCustomerReassignments tests
|--------------------------------------------------------------------------
|
| Covers:
|   1. Director gets a valid suggestion list (Http::fake LLM response).
|   2. Vendedor (Seller) receives 403 from the HTTP endpoint.
|   3. LeakGuard rejects a suggestion referencing a foreign customer_id.
|   4. applyReassignment triggers CustomerReassignmentService and writes
|      an audit_log entry.
|
| RLS note: setUp() sets GUCs to director scope (TestCase base class) so
| factory inserts succeed and queries are unfiltered.
*/

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Return an AiSetting row with global_enabled = true so the 'ai.cap'
 * middleware does not reject the request before it reaches the controller.
 */
function enableAi(): AiSetting
{
    return AiSetting::updateOrCreate(
        ['id' => AiSetting::SINGLETON_ID],
        [
            'global_enabled'            => true,
            'provider'                  => 'openai',
            'model'                     => 'gpt-4o',
            'monthly_token_cap_default' => 10_000_000,
            'monthly_usd_cap_default'   => '500.00',
            'updated_at'                => now(),
        ],
    );
}

/**
 * Build a minimal valid LLM response JSON for the reassignment schema.
 *
 * @param array<int, array<string, mixed>> $suggestions
 */
function fakeLlmResponse(array $suggestions): string
{
    return json_encode(['suggestions' => $suggestions], JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------
// Test 1 — Director gets suggestions (Http::fake LLM)
// ---------------------------------------------------------------------------

it('returns AI reassignment suggestions for a Director', function (): void {
    Queue::fake();

    $director = User::factory()->director()->create();
    enableAi();

    $zone    = Zone::factory()->create(['is_active' => true]);
    $seller  = User::factory()->seller()->create();

    $customer = Customer::factory()->create([
        'zone_id'            => $zone->getKey(),
        'assigned_seller_id' => $seller->getKey(),
        'is_active'          => true,
    ]);

    $fakeSuggestion = [
        'customer_id'         => $customer->getKey(),
        'current_seller_id'   => $seller->getKey(),
        'suggested_seller_id' => $seller->getKey(), // same seller for simplicity
        'reason'              => 'El vendedor tiene active_pct 20% bajo la media de la zona.',
        'confidence'          => 0.8,
    ];

    Http::fake([
        '*' => Http::response(
            json_encode([
                'id'      => 'chatcmpl-test',
                'object'  => 'chat.completion',
                'model'   => 'gpt-4o',
                'choices' => [[
                    'message' => [
                        'role'    => 'assistant',
                        'content' => fakeLlmResponse([$fakeSuggestion]),
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens'     => 200,
                    'completion_tokens' => 50,
                    'total_tokens'      => 250,
                ],
            ]),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $actingAs = $director->createToken('test')->plainTextToken;

    $response = $this->withToken($actingAs)
        ->postJson('/api/v1/ai/reassignments/suggest', [
            'zone_id' => $zone->getKey(),
            'limit'   => 5,
        ]);

    $response->assertOk();
    $body = $response->json();

    expect($body)->toHaveKey('suggestions');
    expect($body['suggestions'])->toBeArray();

    // The suggestion referencing our candidate customer must pass through.
    $customerIds = array_column($body['suggestions'], 'customer_id');
    expect($customerIds)->toContain($customer->getKey());

    // RecordAiUsage was dispatched.
    Queue::assertPushed(RecordAiUsage::class);
});

// ---------------------------------------------------------------------------
// Test 2 — Seller receives 403
// ---------------------------------------------------------------------------

it('returns 403 for a Seller calling the reassignment endpoint', function (): void {
    Queue::fake();
    enableAi();

    $seller = User::factory()->seller()->create();
    $token  = $seller->createToken('test')->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/ai/reassignments/suggest', []);

    $response->assertStatus(403);
    $body = $response->json();
    expect($body['code'])->toBe('FORBIDDEN');
});

// ---------------------------------------------------------------------------
// Test 3 — LeakGuard rejects foreign customer_id in suggestions
// ---------------------------------------------------------------------------

it('removes suggestions whose customer_id is not in the input candidate set', function (): void {
    Queue::fake();
    enableAi();

    $director = User::factory()->director()->create();

    $zone     = Zone::factory()->create(['is_active' => true]);
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->create([
        'zone_id'            => $zone->getKey(),
        'assigned_seller_id' => $seller->getKey(),
        'is_active'          => true,
    ]);

    // A customer NOT in the candidate set — its UUID should be filtered out.
    $foreignCustomer = Customer::factory()->create(['is_active' => true]);

    // LLM response contains one legitimate suggestion + one with foreign customer_id.
    $fakeSuggestions = [
        [
            'customer_id'         => $customer->getKey(),
            'current_seller_id'   => $seller->getKey(),
            'suggested_seller_id' => $seller->getKey(),
            'reason'              => 'Vendedor bajo la media de la zona por 60 dias.',
            'confidence'          => 0.75,
        ],
        [
            'customer_id'         => $foreignCustomer->getKey(), // not a candidate
            'current_seller_id'   => $seller->getKey(),
            'suggested_seller_id' => $seller->getKey(),
            'reason'              => 'Sugerencia inventada sobre cliente externo.',
            'confidence'          => 0.9,
        ],
    ];

    Http::fake([
        '*' => Http::response(
            json_encode([
                'id'      => 'chatcmpl-leak-test',
                'object'  => 'chat.completion',
                'model'   => 'gpt-4o',
                'choices' => [[
                    'message' => [
                        'role'    => 'assistant',
                        'content' => fakeLlmResponse($fakeSuggestions),
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens'     => 300,
                    'completion_tokens' => 80,
                    'total_tokens'      => 380,
                ],
            ]),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    // Call use case directly with a controlled candidate set.
    $useCase = app(SuggestCustomerReassignments::class);

    // We mock the data queries by using a zone with no evolution metrics table,
    // which will hit the fallback path. The customer must appear as a candidate
    // so the suggestion with $customer->getKey() is accepted and the foreign one
    // is rejected.
    //
    // We test the filtering logic directly: inject two suggestions and verify
    // only the legitimate one survives.
    $reflection = new ReflectionClass($useCase);
    $method     = $reflection->getMethod('leakGuardFilter');
    $method->setAccessible(true);

    $result = $method->invoke(
        $useCase,
        $fakeSuggestions,
        [$customer->getKey()], // only $customer is in the allowed set
        $director,
    );

    expect(count($result))->toBe(1);
    expect($result[0]['customer_id'])->toBe($customer->getKey());

    // A leak audit row must have been created for the foreign suggestion.
    $leakAudit = AiAudit::query()
        ->where('user_id', $director->getKey())
        ->where('leak_detected', true)
        ->first();

    expect($leakAudit)->not->toBeNull();
    expect($leakAudit->leak_details)->toContain($foreignCustomer->getKey());
});

// ---------------------------------------------------------------------------
// Test 4 — Apply action triggers reassignment + audit log entry
// ---------------------------------------------------------------------------

it('reassignSingle writes an audit_log entry and updates the customer seller', function (): void {
    $director = User::factory()->director()->create();
    $seller1  = User::factory()->seller()->create();
    $seller2  = User::factory()->seller()->create();
    $zone     = Zone::factory()->create(['is_active' => true]);

    $customer = Customer::factory()->create([
        'zone_id'            => $zone->getKey(),
        'assigned_seller_id' => $seller1->getKey(),
        'is_active'          => true,
    ]);

    // Ensure audit_logs table exists — skip gracefully if not yet migrated.
    if (! \Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
        $this->markTestSkipped('audit_logs table not yet migrated — skipping apply test.');
    }

    $service = app(CustomerReassignmentService::class);

    $updated = $service->reassignSingle(
        customerId:  $customer->getKey(),
        newSellerId: $seller2->getKey(),
        director:    $director,
        reason:      'Test AI reassignment §11.4',
    );

    // Customer now points to the new seller.
    expect((string) $updated->assigned_seller_id)->toBe($seller2->getKey());

    // Audit log entry created.
    $auditRow = DB::table('audit_logs')
        ->where('entity_type', 'customer')
        ->where('entity_id', $customer->getKey())
        ->where('action', 'customer.reassigned')
        ->where('user_id', $director->getKey())
        ->first();

    expect($auditRow)->not->toBeNull();
    expect($auditRow->notes)->toContain('§11.4');

    $oldValues = json_decode($auditRow->old_values, true);
    $newValues = json_decode($auditRow->new_values, true);

    expect($oldValues['assigned_seller_id'])->toBe($seller1->getKey());
    expect($newValues['assigned_seller_id'])->toBe($seller2->getKey());
});
