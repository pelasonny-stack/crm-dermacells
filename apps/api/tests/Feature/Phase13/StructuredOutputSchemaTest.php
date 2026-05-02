<?php

declare(strict_types=1);

use App\Models\AiSetting;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 13 — Structured Output Schema Test (§11.5 verification)
|--------------------------------------------------------------------------
|
| /ai/customers/{id}/suggest-next must return an object with
| { action_type, urgency, reason } (the strict response schema).
*/

beforeEach(function (): void {
    $this->user     = User::factory()->seller()->create();
    $this->customer = Customer::factory()->create([
        'assigned_seller_id' => $this->user->getKey(),
    ]);

    $s = AiSetting::current();
    $s->global_enabled    = true;
    $s->provider          = 'openai';
    $s->model             = 'gpt-4o-mini';
    $s->api_key_encrypted = null;
    $s->save();
    config(['services.openai.key' => 'sk-test']);
});

it('returns { action_type, urgency, reason } with valid enum values', function (): void {
    $structured = json_encode([
        'action_type' => 'reorder_proposal',
        'urgency'     => 'high',
        'reason'      => 'Última compra hace 45 días, frecuencia esperada 30 días.',
    ]);

    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => ['content' => $structured],
            ]],
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 25],
        ], 200),
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/ai/customers/{$this->customer->getKey()}/suggest-next");

    $response->assertOk();
    $body = $response->json();

    expect($body)->toHaveKeys(['action_type', 'urgency', 'reason']);
    expect($body['action_type'])->toBeIn(['call', 'visit', 'reorder_proposal', 'new_product_offer']);
    expect($body['urgency'])->toBeIn(['low', 'medium', 'high']);
    expect($body['reason'])->toBeString()->not->toBeEmpty();
});

it('forwards response_format json_schema in the OpenAI request payload', function (): void {
    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'action_type' => 'call', 'urgency' => 'low', 'reason' => 'no urgency',
                ])],
            ]],
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200),
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/ai/customers/{$this->customer->getKey()}/suggest-next");

    Http::assertSent(function ($request) {
        $body = $request->data();
        return ($body['response_format']['type'] ?? null) === 'json_schema'
            && ($body['response_format']['json_schema']['strict'] ?? null) === true;
    });
});
