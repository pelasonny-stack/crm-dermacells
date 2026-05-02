<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Services\Llm\Dto\LLMRequest;
use App\Services\Llm\Dto\LLMResponse;
use Generator;

/**
 * Provider-agnostic chat / streaming contract (Phase 13).
 *
 * Implementations: AnthropicHttpClient, OpenAIClient. Selection happens at
 * runtime via LLMClientFactory which reads AiSetting::current()->provider.
 *
 * `chat()` is a synchronous request/response call that returns a fully
 * populated LLMResponse including token usage.
 *
 * `stream()` returns a PHP generator that yields incremental string
 * fragments suitable for direct relay through `response()->eventStream()`.
 * The generator's *return value* (caught with `$generator->getReturn()`)
 * is the final LLMResponse with cumulative token usage — callers MUST drain
 * the generator before reading totals.
 */
interface LLMClient
{
    public function chat(LLMRequest $request): LLMResponse;

    /**
     * Stream a completion as a generator of string deltas.
     *
     * @return Generator<int, string, mixed, LLMResponse>
     */
    public function stream(LLMRequest $request): Generator;
}
