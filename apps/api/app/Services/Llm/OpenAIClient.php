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
 * OpenAI Chat Completions client (Phase 13).
 *
 * Although `openai-php/laravel` is installed, its singleton uses the
 * env-bound OPENAI_API_KEY at boot time, which fights the Phase 13
 * requirement that providers be runtime-configurable from the Filament
 * panel. We therefore speak the wire protocol via Http (same approach
 * as AnthropicHttpClient) and pull the API key from AiSetting at call
 * time, decrypting on demand.
 *
 * PROMPT CACHING
 * ==============
 * OpenAI auto-caches stable prefixes >= 1024 tokens. We do not need
 * explicit markers — but the request is structured so that:
 *   - system (rules) and developer (context) come first
 *   - user message is last
 * which gives the auto-cache a stable prefix to identify.
 *
 * STRUCTURED OUTPUT
 * =================
 * `response_format: { type: 'json_schema', json_schema: { strict: true, ... } }`
 * forces the model to return JSON matching the schema. Available on
 * gpt-4o, gpt-4o-mini, gpt-4.1, gpt-5, etc.
 */
final class OpenAIClient implements LLMClient
{
    public function __construct(
        private readonly AiSetting $settings,
    ) {}

    public function chat(LLMRequest $request): LLMResponse
    {
        $payload = $this->buildPayload($request, stream: false);

        $start    = (int) (microtime(true) * 1000);
        $response = $this->http()->post('/chat/completions', $payload);
        $latency  = (int) (microtime(true) * 1000) - $start;

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenAI API call failed: HTTP {$response->status()} — " . $response->body(),
            );
        }

        $requestId = $response->header('x-request-id') ?: null;

        return $this->buildResponse($response->json(), $request, $latency, $requestId);
    }

    public function stream(LLMRequest $request): Generator
    {
        // See AnthropicHttpClient::stream() for rationale. We approximate
        // streaming by chunking the synchronous response on whitespace.
        $response = $this->chat($request);

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
     * @return array<string, mixed>
     */
    private function buildPayload(LLMRequest $request, bool $stream): array
    {
        // Stable prefix structure for OpenAI's auto prompt cache:
        //   1. system rules (rarely change)
        //   2. system-context (changes per customer; still cacheable per-customer)
        //   3. user query (volatile)
        $messages = [
            ['role' => 'system', 'content' => $request->system],
        ];
        if ($request->systemContext !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->systemContext];
        }
        foreach ($request->messages as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $body = [
            'model'       => $this->settings->model,
            'messages'    => $messages,
            'max_tokens'  => $request->maxTokens,
            'temperature' => $request->temperature,
            // Surface usage in the response (default for chat completions but
            // explicit for clarity and to enable cached_tokens reporting).
            'stream'      => $stream,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn (array $t): array => [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'] ?? '',
                    'parameters'  => $t['parameters'] ?? ['type' => 'object'],
                ],
            ], $request->tools);
        }

        if ($request->responseSchema !== null) {
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'structured_response',
                    'strict' => true,
                    'schema' => $request->responseSchema,
                ],
            ];
        }

        return $body;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $apiKey = $this->resolveApiKey();
        $base   = $this->settings->endpoint ?: 'https://api.openai.com/v1';

        return Http::baseUrl($base)
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(60);
    }

    private function resolveApiKey(): string
    {
        if ($this->settings->api_key_encrypted) {
            try {
                return Crypt::decryptString($this->settings->api_key_encrypted);
            } catch (\Throwable) {
                // Fall through to env.
            }
        }

        $envKey = config('services.openai.key');
        if (! $envKey) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }
        return (string) $envKey;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function buildResponse(
        array $body,
        LLMRequest $request,
        int $latency,
        ?string $providerRequestId,
    ): LLMResponse {
        $choice    = $body['choices'][0] ?? [];
        $message   = $choice['message'] ?? [];
        $text      = (string) ($message['content'] ?? '');
        $toolCalls = [];
        $parsedJson = null;

        foreach ((array) ($message['tool_calls'] ?? []) as $tc) {
            $args = json_decode((string) ($tc['function']['arguments'] ?? '{}'), true) ?? [];
            $toolCalls[] = [
                'name'      => (string) ($tc['function']['name'] ?? ''),
                'arguments' => is_array($args) ? $args : [],
                'id'        => (string) ($tc['id'] ?? ''),
            ];
        }

        // Strict JSON schema responses arrive as a JSON string in `content`.
        if ($request->responseSchema !== null && $text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $parsedJson = $decoded;
            }
        }

        $usage              = $body['usage'] ?? [];
        $inputTokens        = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens       = (int) ($usage['completion_tokens'] ?? 0);
        $cachedInputTokens  = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        return new LLMResponse(
            text:              $text,
            parsed:            $parsedJson,
            toolCalls:         $toolCalls,
            inputTokens:       $inputTokens,
            cachedInputTokens: $cachedInputTokens,
            outputTokens:      $outputTokens,
            provider:          'openai',
            model:             (string) ($body['model'] ?? $this->settings->model ?? ''),
            latencyMs:         $latency,
            providerRequestId: $providerRequestId,
            raw:               $body,
        );
    }
}
