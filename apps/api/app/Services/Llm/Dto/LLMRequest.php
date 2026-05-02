<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Provider-agnostic LLM request value object (Phase 13).
 *
 * Both OpenAIClient and AnthropicHttpClient accept this DTO and translate
 * it to the wire format expected by their respective APIs.
 *
 * The `system` field is the *stable prefix* — it is the cacheable portion
 * (Anthropic ephemeral cache_control marker; OpenAI auto-prefix cache).
 * Customer context goes in `system_context` (also cacheable). User input
 * is the only volatile part and lives in `messages`.
 *
 * `tools` carry function-calling definitions; OpenAIClient passes them
 * through `tools` array; AnthropicHttpClient maps them to `tools` with
 * input_schema.
 *
 * `response_schema` (optional) requests strict structured output. For
 * OpenAI this becomes `response_format: { type: 'json_schema', strict: true }`.
 * For Anthropic this is enforced via tool-choice on a single tool whose
 * `input_schema` is the schema (Anthropic's documented "structured outputs"
 * pattern).
 */
final class LLMRequest
{
    /**
     * @param string                                $system          stable system prompt (cacheable)
     * @param string                                $systemContext   customer / user context (cacheable)
     * @param array<int, array{role: string, content: string}> $messages user-turn messages
     * @param array<int, array<string, mixed>>      $tools           function-calling tool definitions
     * @param array<string, mixed>|null             $responseSchema  strict JSON schema for structured output
     * @param int                                   $maxTokens       upper bound on output tokens
     * @param float                                 $temperature     sampling temperature
     * @param bool                                  $stream          whether to request SSE streaming
     * @param string|null                           $injectedCustomerId customer UUID injected into context (for LeakGuard)
     * @param string|null                           $requestId       client-generated id for tracing
     */
    public function __construct(
        public readonly string $system,
        public readonly string $systemContext = '',
        public readonly array $messages = [],
        public readonly array $tools = [],
        public readonly ?array $responseSchema = null,
        public readonly int $maxTokens = 1024,
        public readonly float $temperature = 0.2,
        public readonly bool $stream = false,
        public readonly ?string $injectedCustomerId = null,
        public readonly ?string $requestId = null,
    ) {}
}
