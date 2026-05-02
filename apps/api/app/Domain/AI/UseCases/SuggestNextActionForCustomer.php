<?php

declare(strict_types=1);

namespace App\Domain\AI\UseCases;

use App\Domain\AI\Services\CustomerContextBuilder;
use App\Domain\AI\Services\LeakGuard;
use App\Jobs\AI\RecordAiUsage;
use App\Models\AiAudit;
use App\Models\Customer;
use App\Models\User;
use App\Services\Llm\Dto\LLMRequest;
use App\Services\Llm\LLMClientFactory;
use RuntimeException;

/**
 * SuggestNextActionForCustomer — structured-output use case (Phase 13 — §11.2).
 *
 * Returns { action_type, urgency, reason } for the given customer, where:
 *   action_type: 'call' | 'visit' | 'reorder_proposal' | 'new_product_offer'
 *   urgency:     'low' | 'medium' | 'high'
 *   reason:      free-text justification
 *
 * Pipeline:
 *   1. Build customer context (CustomerContextBuilder).
 *   2. Send LLMRequest with strict JSON schema (responseSchema set).
 *   3. Run LeakGuard against the response payload.
 *   4. Persist audit row + usage.
 *   5. Return the parsed structured response (or throw on leak).
 */
final class SuggestNextActionForCustomer
{
    public function __construct(
        private readonly LLMClientFactory $factory,
        private readonly CustomerContextBuilder $contextBuilder,
        private readonly LeakGuard $leakGuard,
    ) {}

    /**
     * @return array{action_type: string, urgency: string, reason: string}
     */
    public function execute(Customer $customer, User $caller): array
    {
        $context = $this->contextBuilder->renderJson($customer, $caller);

        $request = new LLMRequest(
            system: $this->systemPrompt(),
            systemContext: $context,
            messages: [
                [
                    'role' => 'user',
                    'content' => "Sugerí la próxima acción para el cliente {$customer->getKey()}.",
                ],
            ],
            tools: [],
            responseSchema: $this->responseSchema(),
            maxTokens: 400,
            temperature: 0.1,
            stream: false,
            injectedCustomerId: $customer->getKey(),
        );

        $client   = $this->factory->make();
        $response = $client->chat($request);

        // Anti-leak scan over text + parsed structured payload.
        $leak = $this->leakGuard->validate(
            $response->parsed ?? $response->text,
            $customer->getKey(),
            $caller->getKey(),
        );

        if ($leak->leaked) {
            // Audit row already inserted by LeakGuard; surface a generic error.
            throw new RuntimeException('AI response failed safety review.');
        }

        // Audit clean responses too (forensic baseline).
        try {
            AiAudit::create([
                'user_id'          => $caller->getKey(),
                'customer_id'      => $customer->getKey(),
                'request_payload'  => ['model' => $response->model, 'context_size' => strlen($context)],
                'response_payload' => $response->parsed ?? ['text' => $response->text],
                'leak_detected'    => false,
            ]);
        } catch (\Throwable) {
            // Non-fatal.
        }

        RecordAiUsage::dispatch(
            userId: $caller->getKey(),
            customerId: $customer->getKey(),
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
            'action_type' => (string) ($parsed['action_type'] ?? 'call'),
            'urgency'     => (string) ($parsed['urgency'] ?? 'medium'),
            'reason'      => (string) ($parsed['reason'] ?? ''),
        ];
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
                'action_type' => [
                    'type' => 'string',
                    'enum' => ['call', 'visit', 'reorder_proposal', 'new_product_offer'],
                ],
                'urgency' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                ],
                'reason' => [
                    'type'      => 'string',
                    'minLength' => 1,
                    'maxLength' => 500,
                ],
            ],
            'required' => ['action_type', 'urgency', 'reason'],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You are the AI sales-manager assistant for CRM Dermacells.

        Your task: read the supplied per-customer context (last 20 transactions,
        evolution metrics, last 10 WhatsApp messages, scheduled action notes,
        and the customer profile) and suggest the next action the assigned
        seller should take FOR THAT CUSTOMER ONLY.

        STRICT RULES:
        - Output MUST conform to the structured response schema (one tool call).
        - Never reference any customer other than the one in context.
        - Never invent transaction amounts or product names.
        - `reason` must cite at least one specific datum from the context.
        - Spanish (Argentina) language for `reason`.
        PROMPT;
    }
}
