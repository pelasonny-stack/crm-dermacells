<?php

declare(strict_types=1);

use App\Jobs\AI\RecordAiUsage;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\ModelPricing;
use App\Models\User;
use App\Services\Llm\AnthropicHttpClient;
use App\Services\Llm\Dto\LLMRequest;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 13 — Token Counter Test (§11.5)
|--------------------------------------------------------------------------
|
| Verifies that:
|   1. After an LLM call, RecordAiUsage inserts an ai_usage row with the
|      reported token counts.
|   2. Cost is calculated from the active model_pricing row.
*/

beforeEach(function (): void {
    $this->user = User::factory()->seller()->create();

    $this->settings                    = AiSetting::current();
    $this->settings->global_enabled    = true;
    $this->settings->provider          = 'anthropic';
    $this->settings->model             = 'claude-3-5-sonnet-20241022';
    $this->settings->api_key_encrypted = null;
    $this->settings->save();
    config(['services.anthropic.key' => 'test-key']);

    // Active pricing row.
    ModelPricing::create([
        'provider'            => 'anthropic',
        'model'               => 'claude-3-5-sonnet-20241022',
        'input_per_1k'        => '0.003000',
        'cached_input_per_1k' => '0.000300',
        'output_per_1k'       => '0.015000',
        'currency'            => 'USD',
        'effective_from'      => now()->subDay()->toDateString(),
    ]);
});

it('records ai_usage row with token counts after an LLM call', function (): void {
    Http::fake([
        '*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Hola']],
            'model'   => 'claude-3-5-sonnet-20241022',
            'usage'   => [
                'input_tokens'           => 1000,
                'cache_read_input_tokens' => 200,
                'output_tokens'          => 500,
            ],
        ], 200),
    ]);

    $client   = new AnthropicHttpClient($this->settings);
    $response = $client->chat(new LLMRequest(
        system: 'rules',
        messages: [['role' => 'user', 'content' => 'Hola']],
    ));

    // Dispatch RecordAiUsage as the use cases would.
    RecordAiUsage::dispatchSync(
        userId: $this->user->getKey(),
        customerId: null,
        provider: $response->provider,
        model: $response->model,
        inputTokens: $response->inputTokens,
        cachedInputTokens: $response->cachedInputTokens,
        outputTokens: $response->outputTokens,
    );

    $row = AiUsage::query()
        ->where('user_id', $this->user->getKey())
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->input_tokens)->toBe(1000);
    expect($row->cached_input_tokens)->toBe(200);
    expect($row->output_tokens)->toBe(500);
    expect($row->total_tokens)->toBe(1500); // GENERATED column

    // Cost: (800 non-cached input * 0.003 / 1000) + (200 cached * 0.0003 / 1000) + (500 output * 0.015 / 1000)
    //     = 0.0024 + 0.00006 + 0.0075 = 0.00996
    expect((float) $row->cost_estimate_usd)->toEqualWithDelta(0.0100, 0.0005);
});

it('records zero cost when no pricing row is active', function (): void {
    // Wipe pricing.
    ModelPricing::query()->delete();

    RecordAiUsage::dispatchSync(
        userId: $this->user->getKey(),
        customerId: null,
        provider: 'anthropic',
        model: 'claude-3-5-sonnet-20241022',
        inputTokens: 100,
        cachedInputTokens: 0,
        outputTokens: 50,
    );

    $row = AiUsage::query()
        ->where('user_id', $this->user->getKey())
        ->latest('id')
        ->first();

    expect((float) $row->cost_estimate_usd)->toBe(0.0);
});
