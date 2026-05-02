<?php

declare(strict_types=1);

use App\Models\AiSetting;
use App\Services\Llm\AnthropicHttpClient;
use App\Services\Llm\LLMClientFactory;
use App\Services\Llm\OpenAIClient;

/*
|--------------------------------------------------------------------------
| Phase 13 — Provider Abstraction Test (§11.5)
|--------------------------------------------------------------------------
|
| Verifies that LLMClientFactory dispatches to the correct concrete
| LLMClient implementation based on AiSetting::current()->provider, and
| that switching providers takes effect WITHOUT a process restart
| (settings are read fresh on each ->make() call).
*/

it('returns AnthropicHttpClient when provider is anthropic', function (): void {
    $settings                 = AiSetting::current();
    $settings->global_enabled = true;
    $settings->provider       = 'anthropic';
    $settings->model          = 'claude-sonnet-4-7-20260207';
    $settings->save();

    $client = (new LLMClientFactory())->make();

    expect($client)->toBeInstanceOf(AnthropicHttpClient::class);
});

it('returns OpenAIClient when provider is openai', function (): void {
    $settings                 = AiSetting::current();
    $settings->global_enabled = true;
    $settings->provider       = 'openai';
    $settings->model          = 'gpt-4o-mini';
    $settings->save();

    $client = (new LLMClientFactory())->make();

    expect($client)->toBeInstanceOf(OpenAIClient::class);
});

it('switches provider at runtime without redeploy', function (): void {
    $settings                 = AiSetting::current();
    $settings->global_enabled = true;
    $settings->provider       = 'openai';
    $settings->model          = 'gpt-4o-mini';
    $settings->save();

    $factory = new LLMClientFactory();
    expect($factory->make())->toBeInstanceOf(OpenAIClient::class);

    // Director flips the switch in Filament — same factory instance.
    $settings->provider = 'anthropic';
    $settings->model    = 'claude-3-5-sonnet-20241022';
    $settings->save();

    expect($factory->make())->toBeInstanceOf(AnthropicHttpClient::class);
});

it('throws when global_enabled is false', function (): void {
    $settings                 = AiSetting::current();
    $settings->global_enabled = false;
    $settings->save();

    expect(fn () => (new LLMClientFactory())->make())
        ->toThrow(RuntimeException::class, 'globally disabled');
});
