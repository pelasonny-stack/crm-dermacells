<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AI\UseCases\AskNaturalLanguageQuestion;
use App\Domain\AI\UseCases\GenerateDailyDigest;
use App\Domain\AI\UseCases\SuggestNextActionForCustomer;
use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\AiUserOverride;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AIController — REST surface for the AI Assistant module
 * (Phase 13 — §11).
 *
 * Endpoints (all behind 'ai.cap' middleware which enforces global switch,
 * per-user override, and monthly token cap):
 *
 *   POST /api/v1/ai/ask                        — natural-language Q&A (SSE)
 *   POST /api/v1/ai/customers/{id}/suggest-next — structured next action
 *   GET  /api/v1/ai/digest/me                  — cached morning brief
 *   GET  /api/v1/ai/usage/me                   — current month usage + cost
 */
final class AIController extends Controller
{
    /**
     * Natural-language question; streams text/event-stream response.
     */
    public function ask(
        Request $request,
        AskNaturalLanguageQuestion $useCase,
    ): StreamedResponse {
        $question = (string) $request->input('question', '');
        if ($question === '') {
            abort(422, 'question is required');
        }

        $caller = $request->user();

        return response()->eventStream(function () use ($useCase, $caller, $question) {
            foreach ($useCase->stream($caller, $question) as $chunk) {
                yield $chunk;
            }
        });
    }

    /**
     * POST /ai/customers/{customer}/suggest-next — strict-schema next action.
     */
    public function suggestNext(
        Customer $customer,
        Request $request,
        SuggestNextActionForCustomer $useCase,
    ): JsonResponse {
        $caller = $request->user();
        $result = $useCase->execute($customer, $caller);

        return response()->json($result);
    }

    /**
     * GET /ai/digest/me — daily digest (cached 24h).
     */
    public function digest(
        Request $request,
        GenerateDailyDigest $useCase,
    ): JsonResponse {
        $caller = $request->user();
        $result = $useCase->execute($caller);

        return response()->json($result);
    }

    /**
     * GET /ai/usage/me — current calendar month consumption and cost cap.
     */
    public function usage(Request $request): JsonResponse
    {
        $caller   = $request->user();
        $settings = AiSetting::current();
        $override = AiUserOverride::find($caller->getKey());

        $cap     = $override?->monthly_token_cap ?? $settings->monthly_token_cap_default;
        $usdCap  = $override?->monthly_usd_cap   ?? $settings->monthly_usd_cap_default;

        $periodMonth = now()->startOfMonth()->toDateString();

        $aggregate = AiUsage::query()
            ->where('user_id', $caller->getKey())
            ->where('period_month', $periodMonth)
            ->selectRaw('
                COALESCE(SUM(input_tokens), 0)        as input_tokens,
                COALESCE(SUM(cached_input_tokens), 0) as cached_input_tokens,
                COALESCE(SUM(output_tokens), 0)       as output_tokens,
                COALESCE(SUM(total_tokens), 0)        as total_tokens,
                COALESCE(SUM(cost_estimate_usd), 0)   as cost_usd,
                COUNT(*)                              as call_count
            ')
            ->first();

        return response()->json([
            'period_month'        => $periodMonth,
            'input_tokens'        => (int) ($aggregate->input_tokens ?? 0),
            'cached_input_tokens' => (int) ($aggregate->cached_input_tokens ?? 0),
            'output_tokens'       => (int) ($aggregate->output_tokens ?? 0),
            'total_tokens'        => (int) ($aggregate->total_tokens ?? 0),
            'cost_usd'            => (string) ($aggregate->cost_usd ?? '0.0000'),
            'call_count'          => (int) ($aggregate->call_count ?? 0),
            'cap'                 => [
                'tokens'   => (int) $cap,
                'usd'      => (string) $usdCap,
            ],
        ]);
    }
}
