<?php

declare(strict_types=1);

use App\Models\AiSetting;
use App\Services\Llm\AnthropicHttpClient;
use App\Services\Llm\Dto\LLMRequest;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 13 — Prompt Cache Structure Test (§11.5 verification)
|--------------------------------------------------------------------------
|
| Anthropic's prompt cache is opt-in via cache_control: { type: 'ephemeral' }
| markers on the system prompt and tools blocks. This test inspects the
| actual JSON payload sent to the API to confirm the markers are present.
*/

it('marks system prompt blocks with cache_control ephemeral', function (): void {
    Http::fake([
        '*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model'   => 'claude-3-5-sonnet-20241022',
            'usage'   => ['input_tokens' => 1, 'output_tokens' => 1],
        ], 200),
    ]);

    $settings                    = AiSetting::current();
    $settings->global_enabled    = true;
    $settings->provider          = 'anthropic';
    $settings->model             = 'claude-3-5-sonnet-20241022';
    $settings->api_key_encrypted = null;
    $settings->save();

    config(['services.anthropic.key' => 'test-key']);

    $client = new AnthropicHttpClient($settings);
    $client->chat(new LLMRequest(
        system: 'You are an AI assistant.',
        systemContext: '{"customer_id":"abc"}',
        messages: [['role' => 'user', 'content' => 'Hi']],
        tools: [
            ['name' => 'noop', 'description' => 'no-op', 'parameters' => ['type' => 'object']],
        ],
    ));

    Http::assertSent(function ($request) {
        $body   = $request->data();
        $system = $body['system'] ?? [];

        // Both system blocks must carry cache_control markers.
        expect($system)->toBeArray()->toHaveCount(2);
        expect($system[0]['cache_control']['type'] ?? null)->toBe('ephemeral');
        expect($system[1]['cache_control']['type'] ?? null)->toBe('ephemeral');

        // The (last) tool must carry cache_control too — entire tool block is cached.
        $tools = $body['tools'] ?? [];
        expect($tools)->toBeArray()->not->toBeEmpty();
        $last = end($tools);
        expect($last['cache_control']['type'] ?? null)->toBe('ephemeral');

        return true;
    });
});

it('forces tool_choice when responseSchema is provided (structured output)', function (): void {
    Http::fake([
        '*' => Http::response([
            'content' => [
                [
                    'type'  => 'tool_use',
                    'name'  => 'emit_structured_response',
                    'input' => ['action_type' => 'call', 'urgency' => 'low', 'reason' => 'because'],
                    'id'    => 'tool_1',
                ],
            ],
            'model' => 'claude-3-5-sonnet-20241022',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
        ], 200),
    ]);

    $settings                    = AiSetting::current();
    $settings->global_enabled    = true;
    $settings->provider          = 'anthropic';
    $settings->model             = 'claude-3-5-sonnet-20241022';
    $settings->api_key_encrypted = null;
    $settings->save();
    config(['services.anthropic.key' => 'test-key']);

    $client = new AnthropicHttpClient($settings);
    $resp   = $client->chat(new LLMRequest(
        system: 'rules',
        systemContext: '{}',
        messages: [['role' => 'user', 'content' => 'go']],
        responseSchema: [
            'type'       => 'object',
            'properties' => [
                'action_type' => ['type' => 'string'],
                'urgency'     => ['type' => 'string'],
                'reason'      => ['type' => 'string'],
            ],
            'required' => ['action_type', 'urgency', 'reason'],
        ],
    ));

    expect($resp->parsed)->toBe([
        'action_type' => 'call',
        'urgency'     => 'low',
        'reason'      => 'because',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();
        return ($body['tool_choice']['type'] ?? null) === 'tool'
            && ($body['tool_choice']['name'] ?? null) === 'emit_structured_response';
    });
});
