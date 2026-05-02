<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Provider-agnostic LLM response value object (Phase 13).
 *
 * `text` holds the assistant's free-form completion. For structured-output
 * requests (responseSchema set on the request), `parsed` holds the decoded
 * JSON object — callers should prefer `parsed` when present.
 *
 * `toolCalls` lists function-call invocations the model emitted; the
 * use-case orchestrator dispatches them to App\Domain\AI\Tools and folds
 * results back into a follow-up turn (when the use case implements multi-turn).
 *
 * Token usage fields feed RecordAiUsage:
 *   inputTokens         — non-cached input
 *   cachedInputTokens   — cache-hit input (Anthropic cache_read_input_tokens
 *                         or OpenAI prompt_cache_hit)
 *   outputTokens        — completion tokens
 */
final class LLMResponse
{
    /**
     * @param string                                $text          assistant completion (free-text)
     * @param array<string, mixed>|null             $parsed        structured-output JSON, when requested
     * @param array<int, array<string, mixed>>      $toolCalls     function calls emitted by the model
     * @param int                                   $inputTokens   non-cached input tokens
     * @param int                                   $cachedInputTokens cache-hit input tokens
     * @param int                                   $outputTokens  completion tokens
     * @param string                                $provider      'openai' | 'anthropic'
     * @param string                                $model         exact model identifier returned by the API
     * @param int|null                              $latencyMs     wall-clock latency of the call
     * @param string|null                           $providerRequestId provider-issued request id (Anthropic `request-id` header, etc.)
     * @param array<string, mixed>                  $raw           full provider payload (for ai_audit JSON storage)
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $parsed,
        public readonly array $toolCalls,
        public readonly int $inputTokens,
        public readonly int $cachedInputTokens,
        public readonly int $outputTokens,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $latencyMs,
        public readonly ?string $providerRequestId,
        public readonly array $raw,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
