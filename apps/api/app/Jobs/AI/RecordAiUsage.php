<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AiUsage;
use App\Models\ModelPricing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * RecordAiUsage — queueable job that persists per-call token usage and
 * estimated cost (Phase 13 — §11.5 token monitor).
 *
 * Cost is computed against the active row in model_pricing for
 * (provider, model). When no pricing is declared the row is inserted
 * with cost_estimate_usd = 0 (Director sees this in the monitor and is
 * expected to populate the price list).
 */
class RecordAiUsage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly ?string $customerId,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $cachedInputTokens,
        public readonly int $outputTokens,
        public readonly ?int $latencyMs = null,
        public readonly ?string $requestId = null,
    ) {
        // Run on the 'low' queue per Phase 0 queue strategy.
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $cost = $this->computeCost();

        AiUsage::create([
            'user_id'             => $this->userId,
            'customer_id'         => $this->customerId,
            'period_month'        => Carbon::now()->startOfMonth()->toDateString(),
            'provider'            => $this->provider,
            'model'               => $this->model,
            'input_tokens'        => $this->inputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'output_tokens'       => $this->outputTokens,
            'cost_estimate_usd'   => $cost,
            'request_id'          => $this->requestId,
            'latency_ms'          => $this->latencyMs,
            'created_at'          => now(),
        ]);
    }

    /**
     * Compute cost in USD against the active model_pricing row.
     */
    private function computeCost(): string
    {
        $pricing = ModelPricing::activeFor($this->provider, $this->model);
        if ($pricing === null) {
            return '0.0000';
        }

        $nonCachedInput = max(0, $this->inputTokens - $this->cachedInputTokens);

        $inputCost  = ((float) $pricing->input_per_1k) * ($nonCachedInput / 1000);
        $cacheCost  = ((float) ($pricing->cached_input_per_1k ?? $pricing->input_per_1k)) * ($this->cachedInputTokens / 1000);
        $outputCost = ((float) $pricing->output_per_1k) * ($this->outputTokens / 1000);

        $total = $inputCost + $cacheCost + $outputCost;

        return number_format($total, 4, '.', '');
    }
}
