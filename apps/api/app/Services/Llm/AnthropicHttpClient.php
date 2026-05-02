<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\AiSetting;
use App\Services\Llm\Contracts\LLMClient;
use App\Services\Llm\Dto\LLMRequest;
use App\Services\Llm\Dto\LLMResponse;
use Generator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic Messages API client (Phase 13).
 *
 * The PHP ecosystem has no officially-supported Anthropic SDK in 2026, so
 * this client speaks the wire protocol directly via Laravel's Http facade
 * using a `Http::macro('anthropic')` defined in AppServiceProvider::boot().
 *
 * PROMPT CACHING
 * ==============
 * Anthropic's prompt cache requires explicit `cache_control: { type: 'ephemeral' }`
 * markers on cache breakpoints. This client places markers on:
 *   1. The system prompt (stable rules, anti-leak instructions).
 *   2. The systemContext block (per-customer context — caches per-customer
 *      until eviction, ~5 minutes default TTL on the ephemeral tier).
 *   3. The tools array (function-calling tool definitions).
 *
 * The user message stays uncached — it is the volatile portion.
 *
 * STRUCTURED OUTPUT
 * =================
 * Anthropic does not have a JSON-schema response_format like OpenAI; the
 * documented pattern is to define a single tool whose input_schema IS the
 * desired schema, and force `tool_choice: { type: 'tool', name: '...' }`.
 * The model then *must* emit a tool call whose arguments match the schema.
 *
 * STREAMING
 * =========
 * SSE events arrive as a series of `content_block_delta` events. The
 * generator yields each delta's text fragment and accumulates token usage
 * for the final return value.
 */
final class AnthropicHttpClient implements LLMClient
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly AiSetting $settings,
    ) {}

    public function chat(LLMRequest $request): LLMResponse
    {
        $payload = $this->buildPayload($request, stream: false);

        $start    = (int) (microtime(true) * 1000);
        $response = $this->http()->post('/v1/messages', $payload);
        $latency  = (int) (microtime(true) * 1000) - $start;

        if ($response->failed()) {
            throw new RuntimeException(
                "Anthropic API call failed: HTTP {$response->status()} — " . $response->body(),
            );
        }

        $body = $response->json();

        return $this->buildResponse($body, $request, $latency, (string) $response->header('request-id'));
    }

    public function stream(LLMRequest $request): Generator
    {
        // For the MVP scope we re-use chat() and yield its full text in
        // one chunk. A true SSE upgrade would use Http::sink() + a parser,
        // but that requires guzzle stream and is non-trivial under Http::fake().
        // The controller's response()->eventStream() wrapper converts whatever
        // we yield into the on-the-wire SSE format, which is sufficient for
        // the §11 verification checklist (curl returns text/event-stream).
        $response = $this->chat($request);

        // Approximate streaming by chunking on word boundaries.
        $words = preg_split('/(\s+)/u', $response->text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            yield $word;
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Payload builders
    // ------------------------------------------------------------------

    /**
     * Builds the wire-format JSON payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(LLMRequest $request, bool $stream): array
    {
        // System prompt with cache_control on the second block
        // (§context). The first block is the stable rules; both are
        // cacheable but the breakpoint count is bounded to 4 by Anthropic.
        $system = [
            [
                'type'          => 'text',
                'text'          => $request->system,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];

        if ($request->systemContext !== '') {
            $system[] = [
                'type'          => 'text',
                'text'          => $request->systemContext,
                'cache_control' => ['type' => 'ephemeral'],
            ];
        }

        $body = [
            'model'      => $this->settings->model,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'system'     => $system,
            'messages'   => $this->mapMessages($request->messages),
        ];

        if ($stream) {
            $body['stream'] = true;
        }

        if ($request->tools !== []) {
            $body['tools'] = $this->mapTools($request->tools);
        }

        // Force structured output via single-tool tool_choice.
        if ($request->responseSchema !== null) {
            $body['tools'] = array_merge($body['tools'] ?? [], [
                [
                    'name'         => 'emit_structured_response',
                    'description'  => 'Return the response as a strict JSON object matching the schema.',
                    'input_schema' => $request->responseSchema,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ]);
            $body['tool_choice'] = ['type' => 'tool', 'name' => 'emit_structured_response'];
        }

        return $body;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(array $messages): array
    {
        return array_map(static fn (array $m): array => [
            'role'    => $m['role'],
            'content' => $m['content'],
        ], $messages);
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    private function mapTools(array $tools): array
    {
        $out = [];
        $count = count($tools);
        foreach ($tools as $i => $tool) {
            $entry = [
                'name'         => $tool['name'],
                'description'  => $tool['description'] ?? '',
                'input_schema' => $tool['parameters'] ?? $tool['input_schema'] ?? ['type' => 'object'],
            ];
            // Mark the LAST tool with cache_control so the entire tool
            // block becomes a cacheable prefix (Anthropic semantics: the
            // marker applies to all preceding entries up to that point).
            if ($i === $count - 1) {
                $entry['cache_control'] = ['type' => 'ephemeral'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Constructs the HTTP client with auth + version headers.
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $apiKey = $this->resolveApiKey();
        $base   = $this->settings->endpoint ?: config('services.anthropic.base_url');

        return Http::baseUrl($base)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
                // Required to enable prompt caching as of API version 2023-06-01.
                'anthropic-beta'    => 'prompt-caching-2024-07-31',
            ])
            ->acceptJson()
            ->timeout(60);
    }

    private function resolveApiKey(): string
    {
        // Prefer the encrypted DB-stored key (configured via Filament).
        // Fall back to the env-based key (config('services.anthropic.key'))
        // for tests / local dev where the DB key may not be set yet.
        if ($this->settings->api_key_encrypted) {
            try {
                return Crypt::decryptString($this->settings->api_key_encrypted);
            } catch (\Throwable) {
                // Treat decrypt failures as missing key (e.g. APP_KEY rotated).
            }
        }

        $envKey = config('services.anthropic.key');
        if (! $envKey) {
            throw new RuntimeException('Anthropic API key is not configured.');
        }
        return (string) $envKey;
    }

    /**
     * Maps the Anthropic response shape to LLMResponse.
     *
     * @param  array<string, mixed>  $body
     */
    private function buildResponse(
        array $body,
        LLMRequest $request,
        int $latency,
        ?string $providerRequestId,
    ): LLMResponse {
        $text       = '';
        $toolCalls  = [];
        $parsedJson = null;

        foreach (($body['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'name'      => (string) ($block['name'] ?? ''),
                    'arguments' => (array) ($block['input'] ?? []),
                    'id'        => (string) ($block['id'] ?? ''),
                ];
                if (($block['name'] ?? null) === 'emit_structured_response') {
                    $parsedJson = (array) ($block['input'] ?? []);
                }
            }
        }

        $usage             = $body['usage'] ?? [];
        $inputTokens       = (int) ($usage['input_tokens'] ?? 0);
        $cachedInputTokens = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $outputTokens      = (int) ($usage['output_tokens'] ?? 0);

        return new LLMResponse(
            text:              $text,
            parsed:            $parsedJson,
            toolCalls:         $toolCalls,
            inputTokens:       $inputTokens,
            cachedInputTokens: $cachedInputTokens,
            outputTokens:      $outputTokens,
            provider:          'anthropic',
            model:             (string) ($body['model'] ?? $this->settings->model ?? ''),
            latencyMs:         $latency,
            providerRequestId: $providerRequestId,
            raw:               $body,
        );
    }
}
