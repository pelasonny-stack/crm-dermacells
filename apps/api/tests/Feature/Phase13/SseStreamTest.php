<?php

declare(strict_types=1);

use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 13 — SSE Stream Test (§11.5 verification)
|--------------------------------------------------------------------------
|
| The /ai/ask endpoint must respond with text/event-stream content type
| (response()->eventStream() wraps a Generator into SSE on the wire).
*/

beforeEach(function (): void {
    $this->user = User::factory()->seller()->create();

    $s = AiSetting::current();
    $s->global_enabled    = true;
    $s->provider          = 'openai';
    $s->model             = 'gpt-4o-mini';
    $s->api_key_encrypted = null;
    $s->save();
    config(['services.openai.key' => 'sk-test']);
});

it('returns text/event-stream content type from /ai/ask', function (): void {
    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => ['content' => 'Tres palabras solamente.'],
            ]],
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ], 200),
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/ai/ask', ['question' => '¿Cuál fue mi mejor mes?']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
});
