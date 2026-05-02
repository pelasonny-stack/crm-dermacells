<?php

declare(strict_types=1);

namespace App\Domain\AI\UseCases;

use App\Jobs\AI\RecordAiUsage;
use App\Models\AiAudit;
use App\Models\User;
use App\Services\Llm\Dto\LLMRequest;
use App\Services\Llm\LLMClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GenerateDailyDigest — produces the morning brief for a Vendedor /
 * Distribuidor / Director (Phase 13 — §11.2 "Resumen diario de prioridades").
 *
 * Cached for 24h under cache key `ai:digest:user:{user_id}:{YYYY-MM-DD}` so a
 * second user-driven request the same day is free.
 *
 * Output schema:
 *   { date, priority_customers: [{customer_id, reason, urgency}], summary }
 */
final class GenerateDailyDigest
{
    public function __construct(
        private readonly LLMClientFactory $factory,
    ) {}

    /**
     * @return array{date: string, priority_customers: array<int, array<string, mixed>>, summary: string}
     */
    public function execute(User $caller): array
    {
        $today    = now()->toDateString();
        $cacheKey = "ai:digest:user:{$caller->getKey()}:{$today}";

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($caller, $today): array {
            return $this->generate($caller, $today);
        });
    }

    /**
     * @return array{date: string, priority_customers: array<int, array<string, mixed>>, summary: string}
     */
    private function generate(User $caller, string $today): array
    {
        $context = $this->contextSummary($caller);

        $request = new LLMRequest(
            system: $this->systemPrompt(),
            systemContext: (string) json_encode($context, JSON_UNESCAPED_UNICODE),
            messages: [
                ['role' => 'user', 'content' => "Generá mi resumen del día {$today}."],
            ],
            tools: [],
            responseSchema: $this->responseSchema(),
            maxTokens: 600,
            temperature: 0.2,
            stream: false,
        );

        $client   = $this->factory->make();
        $response = $client->chat($request);

        try {
            AiAudit::create([
                'user_id'          => $caller->getKey(),
                'customer_id'      => null,
                'request_payload'  => ['model' => $response->model, 'kind' => 'daily_digest'],
                'response_payload' => $response->parsed ?? ['text' => $response->text],
                'leak_detected'    => false,
            ]);
        } catch (\Throwable) {
        }

        RecordAiUsage::dispatch(
            userId: $caller->getKey(),
            customerId: null,
            provider: $response->provider,
            model: $response->model,
            inputTokens: $response->inputTokens,
            cachedInputTokens: $response->cachedInputTokens,
            outputTokens: $response->outputTokens,
            latencyMs: $response->latencyMs,
            requestId: $response->providerRequestId,
        );

        $parsed = $response->parsed ?? [];

        return [
            'date'                => $today,
            'priority_customers'  => (array) ($parsed['priority_customers'] ?? []),
            'summary'             => (string) ($parsed['summary'] ?? ''),
        ];
    }

    /**
     * Build a small aggregate summary of the seller's pipeline so the LLM
     * has something concrete to reason over without a per-customer context.
     *
     * @return array<string, mixed>
     */
    private function contextSummary(User $caller): array
    {
        $summary = [
            'caller_id'   => $caller->getKey(),
            'caller_role' => is_object($caller->getAttribute('role'))
                ? $caller->getAttribute('role')->value
                : (string) $caller->getAttribute('role'),
        ];

        if (Schema::hasTable('customers')) {
            $summary['active_customers'] = (int) DB::table('customers')
                ->where('is_active', true)
                ->where('assigned_seller_id', $caller->getKey())
                ->count();
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'priority_customers' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'customer_id' => ['type' => 'string'],
                            'reason'      => ['type' => 'string'],
                            'urgency'     => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        ],
                        'required' => ['customer_id', 'reason', 'urgency'],
                    ],
                ],
                'summary' => ['type' => 'string', 'maxLength' => 800],
            ],
            'required' => ['priority_customers', 'summary'],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You are the AI sales-manager for CRM Dermacells. Generate the morning
        brief in Spanish (Argentina) for the user. Highlight the top customers
        that need attention TODAY based on the supplied aggregate context.

        STRICT RULES:
        - Output MUST conform to the structured response schema.
        - Be concrete; no fluff.
        - Never reference customers outside the user's scope.
        PROMPT;
    }
}
