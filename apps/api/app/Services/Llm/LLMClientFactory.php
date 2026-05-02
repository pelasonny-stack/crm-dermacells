<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\AiSetting;
use App\Services\Llm\Contracts\LLMClient;
use RuntimeException;

/**
 * Factory that resolves the configured LLM client at runtime
 * (Phase 13 — §11.5: provider switch from Filament must take effect
 * without redeploy).
 *
 * Reads AiSetting::current()->provider on every call so the next request
 * after a Filament save observes the new provider — no caching layer here
 * because AiSetting itself is a single PK lookup and the call is cheap.
 */
final class LLMClientFactory
{
    public function make(): LLMClient
    {
        $settings = AiSetting::current();

        if (! $settings->global_enabled) {
            throw new RuntimeException(
                'AI module is globally disabled — no LLM client should be requested.',
            );
        }

        return match ($settings->provider) {
            'openai'    => new OpenAIClient($settings),
            'anthropic' => new AnthropicHttpClient($settings),
            default     => throw new RuntimeException(
                "Unknown AI provider configured: '{$settings->provider}'. Expected 'openai' or 'anthropic'.",
            ),
        };
    }
}
