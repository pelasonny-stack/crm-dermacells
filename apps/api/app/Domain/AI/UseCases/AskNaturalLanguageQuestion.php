<?php

declare(strict_types=1);

namespace App\Domain\AI\UseCases;

use App\Domain\AI\Tools\GetCustomerEvolutionTool;
use App\Domain\AI\Tools\GetMonthlySalesTool;
use App\Domain\AI\Tools\LLMTool;
use App\Domain\AI\Tools\QueryCustomersByInactivityTool;
use App\Jobs\AI\RecordAiUsage;
use App\Models\AiAudit;
use App\Models\User;
use App\Services\Llm\Dto\LLMRequest;
use App\Services\Llm\LLMClientFactory;
use Generator;

/**
 * AskNaturalLanguageQuestion — natural-language Q&A for the AI Assistant
 * (Phase 13 — §11.2 last bullet).
 *
 * Streams a response via SSE. The LLM is supplied with a tool catalogue
 * (tools/) — when it emits a tool call, the orchestrator dispatches the
 * tool with the caller's User and folds results back. For the MVP this
 * is single-pass (model call → optional tool dispatch returned to caller).
 *
 * RBAC: tools are responsible for scoping their queries; this orchestrator
 * does not need to know the caller's role.
 *
 * Token usage is recorded via the queued RecordAiUsage job after the
 * generator drains.
 */
final class AskNaturalLanguageQuestion
{
    public function __construct(
        private readonly LLMClientFactory $factory,
    ) {}

    /**
     * @return Generator<int, string, mixed, void>
     */
    public function stream(User $caller, string $question): Generator
    {
        $client  = $this->factory->make();
        $request = $this->buildRequest($caller, $question);

        $generator = $client->stream($request);

        $accumulated = '';
        foreach ($generator as $chunk) {
            $accumulated .= $chunk;
            yield $chunk;
        }

        /** @var \App\Services\Llm\Dto\LLMResponse $response */
        $response = $generator->getReturn();

        // Audit the request/response (no leak guard here — this isn't a
        // single-customer-context call; the tools enforce RBAC).
        try {
            AiAudit::create([
                'user_id'          => $caller->getKey(),
                'customer_id'      => null,
                'request_payload'  => ['question' => $question, 'model' => $response->model],
                'response_payload' => ['text' => $response->text],
                'leak_detected'    => false,
            ]);
        } catch (\Throwable) {
            // Audit failure must not break the user-visible flow.
        }

        // Persist usage (queued).
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
    }

    private function buildRequest(User $caller, string $question): LLMRequest
    {
        $tools = array_map(
            fn (LLMTool $t): array => [
                'name'        => $t->name(),
                'description' => $t->description(),
                'parameters'  => $t->schema(),
            ],
            $this->toolCatalogue(),
        );

        return new LLMRequest(
            system: $this->systemPrompt(),
            systemContext: '',
            messages: [
                ['role' => 'user', 'content' => $question],
            ],
            tools: $tools,
            responseSchema: null,
            maxTokens: 800,
            temperature: 0.2,
            stream: true,
        );
    }

    /**
     * @return array<int, LLMTool>
     */
    private function toolCatalogue(): array
    {
        return [
            new QueryCustomersByInactivityTool(),
            new GetMonthlySalesTool(),
            new GetCustomerEvolutionTool(),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You are the AI sales-manager assistant for CRM Dermacells. You answer
        questions in Spanish (Argentina) about the user's own sales, customers,
        and pipeline.

        STRICT RULES (NEVER violate):
        - You only see data the user is authorised to see. Tools enforce RBAC
          server-side; do not attempt to bypass them.
        - Never invent customer ids, sales ids, or money amounts.
        - Never reveal data about customers other than the one the user is
          asking about.
        - When you are unsure, say so. Prefer "no tengo esa información" over
          guessing.
        - Use brief, actionable language. The user is a busy field salesperson.
        PROMPT;
    }
}
