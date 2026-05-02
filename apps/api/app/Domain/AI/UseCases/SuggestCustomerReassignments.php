<?php

declare(strict_types=1);

namespace App\Domain\AI\UseCases;

use App\Domain\AI\Services\LeakGuard;
use App\Enums\UserRole;
use App\Jobs\AI\RecordAiUsage;
use App\Models\AiAudit;
use App\Models\User;
use App\Models\Zone;
use App\Services\Llm\Dto\LLMRequest;
use App\Services\Llm\LLMClientFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * SuggestCustomerReassignments — Director-only AI use case (Phase 13 — §11.4).
 *
 * Identifies customers whose evolution_state is 'inactive' or 'decreasing'
 * and whose assigned seller has been underperforming relative to their zone
 * peers over the last 60 days. Sends an aggregated seller-performance table
 * plus candidate list to the configured LLM and returns a structured list
 * of reassignment suggestions, each filtered by LeakGuard.
 *
 * Output schema (per suggestion):
 *   { customer_id, current_seller_id, suggested_seller_id, reason, confidence }
 *
 * Pipeline:
 *   1. Assert caller is Director.
 *   2. Query last 90d purchase metrics per (seller, customer, zone).
 *   3. Identify underperforming sellers per zone.
 *   4. Build candidate list (inactive/decreasing customers of underperformers).
 *   5. Send LLMRequest with structured output schema.
 *   6. LeakGuard: reject suggestions whose customer_id is not in input set.
 *   7. Persist ai_audit + dispatch RecordAiUsage.
 *   8. Return filtered suggestion list.
 */
final class SuggestCustomerReassignments
{
    public function __construct(
        private readonly LLMClientFactory $factory,
        private readonly LeakGuard $leakGuard,
    ) {}

    /**
     * @param Zone|null $zone   Optional zone filter; null = all zones (Director scope).
     * @param int       $limit  Maximum number of suggestions to request from the LLM.
     *
     * @return array<int, array{
     *     customer_id: string,
     *     current_seller_id: string,
     *     suggested_seller_id: string,
     *     reason: string,
     *     confidence: float
     * }>
     */
    public function execute(User $caller, ?Zone $zone = null, int $limit = 20): array
    {
        // 1. Director-only assertion.
        if (! ($caller->role instanceof UserRole) || ! $caller->role->isDirector()) {
            throw new RuntimeException('SuggestCustomerReassignments is restricted to Directors.');
        }

        $since90  = Carbon::now()->subDays(90)->toDateString();
        $since60  = Carbon::now()->subDays(60)->toDateString();
        $zoneId   = $zone?->getKey();

        // 2. Aggregate seller performance metrics per zone over the last 90 days.
        $sellerMetrics = $this->sellerZoneMetrics($zoneId, $since90, $since60);

        if (empty($sellerMetrics)) {
            return [];
        }

        // 3. Identify zone-level averages and flag underperformers.
        $zoneAverages    = $this->computeZoneAverages($sellerMetrics);
        $underperformers = $this->flagUnderperformers($sellerMetrics, $zoneAverages);

        // 4. Build candidate list: inactive/decreasing customers of underperformers.
        $candidates = $this->candidateCustomers($underperformers, $zoneId, $since90);

        if (empty($candidates)) {
            return [];
        }

        // Keep the set of valid customer IDs for LeakGuard post-filtering.
        $validCustomerIds = array_column($candidates, 'customer_id');

        // 5. Compose LLM context.
        $contextJson = (string) json_encode([
            'seller_performance_table' => $sellerMetrics,
            'zone_averages'            => $zoneAverages,
            'underperforming_sellers'  => $underperformers,
            'reassignment_candidates'  => $candidates,
            'analysis_window_days'     => ['metrics' => 90, 'underperformer_lookback' => 60],
            'requested_limit'          => $limit,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $request = new LLMRequest(
            system: $this->systemPrompt(),
            systemContext: $contextJson,
            messages: [
                [
                    'role'    => 'user',
                    'content' => "Basándote en los datos de rendimiento de los vendedores, sugerí hasta {$limit} reasignaciones de clientes. Priorizá clientes con mayor impacto potencial.",
                ],
            ],
            tools: [],
            responseSchema: $this->responseSchema(),
            maxTokens: 2000,
            temperature: 0.1,
            stream: false,
            injectedCustomerId: null, // multi-customer context — LeakGuard applied manually below
        );

        $client   = $this->factory->make();
        $response = $client->chat($request);

        // 6. LeakGuard: scan for each candidate customer_id appearing in the
        //    response, and remove suggestions whose customer_id is not in the
        //    input set. We run a single scan of the serialised payload.
        $parsed = $response->parsed ?? [];
        $suggestions = $parsed['suggestions'] ?? [];

        $filteredSuggestions = $this->leakGuardFilter($suggestions, $validCustomerIds, $caller);

        // 7. Audit row — always persisted for forensic baseline.
        try {
            AiAudit::create([
                'user_id'          => $caller->getKey(),
                'customer_id'      => null, // director-scope, not per-customer
                'request_payload'  => [
                    'model'            => $response->model,
                    'context_size'     => strlen($contextJson),
                    'candidate_count'  => count($candidates),
                    'zone_id'          => $zoneId,
                ],
                'response_payload' => $parsed,
                'leak_detected'    => count($filteredSuggestions) < count($suggestions),
            ]);
        } catch (\Throwable) {
            // Non-fatal.
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

        return array_slice($filteredSuggestions, 0, $limit);
    }

    // =========================================================================
    // Data collection helpers
    // =========================================================================

    /**
     * Aggregated seller performance per zone over the analysis windows.
     *
     * Returns rows: seller_id, zone_id, customer_count, active_pct,
     *               avg_inactive_days, below_zone_avg_60d (bool flag).
     *
     * Gracefully falls back to an empty array if purchase_evolution_metrics
     * does not yet exist in the schema (subagent ordering protection).
     *
     * @return array<int, array<string, mixed>>
     */
    private function sellerZoneMetrics(?string $zoneId, string $since90, string $since60): array
    {
        if (! Schema::hasTable('customers')) {
            return [];
        }

        $evolutionTable = Schema::hasTable('purchase_evolution_metrics')
            ? 'purchase_evolution_metrics'
            : null;

        // Base query: customers with at least one purchase in last 90 days.
        $query = DB::table('customers as c')
            ->join('users as u', 'c.assigned_seller_id', '=', 'u.id')
            ->where('c.is_active', true)
            ->where('u.is_active', true)
            ->where('u.role', UserRole::Seller->value)
            ->select([
                'c.assigned_seller_id as seller_id',
                'u.full_name as seller_name',
                'c.zone_id',
                DB::raw('COUNT(c.id) as customer_count'),
            ])
            ->groupBy('c.assigned_seller_id', 'u.full_name', 'c.zone_id');

        if ($zoneId !== null) {
            $query->where('c.zone_id', $zoneId);
        }

        $baseRows = $query->get()->keyBy(fn ($r) => $r->seller_id . '_' . $r->zone_id);

        if ($baseRows->isEmpty()) {
            return [];
        }

        // Enrich with evolution metrics when the table exists.
        $metrics = [];
        foreach ($baseRows as $key => $row) {
            $activePct       = 100.0;
            $avgInactiveDays = 0;

            if ($evolutionTable !== null) {
                $evStats = DB::table($evolutionTable . ' as em')
                    ->join('customers as c2', 'em.customer_id', '=', 'c2.id')
                    ->where('c2.assigned_seller_id', $row->seller_id)
                    ->where('c2.zone_id', $row->zone_id)
                    ->where('c2.is_active', true)
                    ->whereIn('em.evolution_state', ['inactive', 'decreasing', 'active', 'growing'])
                    ->selectRaw('
                        COUNT(*) FILTER (WHERE em.evolution_state IN (\'active\', \'growing\')) as active_count,
                        COUNT(*) as total_count,
                        AVG(CASE WHEN em.evolution_state = \'inactive\' THEN em.days_since_last_purchase ELSE NULL END) as avg_inactive_days
                    ')
                    ->first();

                if ($evStats && (int) $evStats->total_count > 0) {
                    $activePct       = round((((int) $evStats->active_count) / ((int) $evStats->total_count)) * 100, 1);
                    $avgInactiveDays = (int) round((float) ($evStats->avg_inactive_days ?? 0));
                }
            }

            $metrics[] = [
                'seller_id'        => $row->seller_id,
                'seller_name'      => $row->seller_name,
                'zone_id'          => $row->zone_id,
                'customer_count'   => (int) $row->customer_count,
                'active_pct'       => $activePct,
                'avg_inactive_days' => $avgInactiveDays,
            ];
        }

        return $metrics;
    }

    /**
     * Compute per-zone averages for active_pct.
     *
     * @param  array<int, array<string, mixed>> $metrics
     * @return array<string, array{avg_active_pct: float, seller_count: int}>
     */
    private function computeZoneAverages(array $metrics): array
    {
        $byZone = [];
        foreach ($metrics as $row) {
            $zid = (string) $row['zone_id'];
            $byZone[$zid][] = (float) $row['active_pct'];
        }

        $averages = [];
        foreach ($byZone as $zid => $pcts) {
            $averages[$zid] = [
                'avg_active_pct' => round(array_sum($pcts) / count($pcts), 1),
                'seller_count'   => count($pcts),
            ];
        }
        return $averages;
    }

    /**
     * Flag sellers whose active_pct is below their zone average.
     * Only zones with at least 2 sellers are eligible (no comparison otherwise).
     *
     * @param  array<int, array<string, mixed>>                              $metrics
     * @param  array<string, array{avg_active_pct: float, seller_count: int}> $zoneAverages
     * @return array<int, string> List of underperforming seller UUIDs.
     */
    private function flagUnderperformers(array $metrics, array $zoneAverages): array
    {
        $underperformers = [];
        foreach ($metrics as $row) {
            $zid = (string) $row['zone_id'];
            if (! isset($zoneAverages[$zid])) {
                continue;
            }
            if ($zoneAverages[$zid]['seller_count'] < 2) {
                // Cannot establish a comparison baseline with only one seller.
                continue;
            }
            if ((float) $row['active_pct'] < $zoneAverages[$zid]['avg_active_pct']) {
                $underperformers[] = (string) $row['seller_id'];
            }
        }
        return array_unique($underperformers);
    }

    /**
     * Build the candidate list: customers assigned to underperforming sellers
     * whose evolution_state is 'inactive' or 'decreasing'.
     *
     * Falls back to a last_purchase_date based heuristic when the evolution
     * metrics table is absent.
     *
     * @param  array<int, string> $underperformers Seller UUIDs.
     * @return array<int, array<string, mixed>>
     */
    private function candidateCustomers(array $underperformers, ?string $zoneId, string $since90): array
    {
        if (empty($underperformers)) {
            return [];
        }

        if (Schema::hasTable('purchase_evolution_metrics')) {
            $query = DB::table('customers as c')
                ->join('purchase_evolution_metrics as em', 'em.customer_id', '=', 'c.id')
                ->join('users as u', 'c.assigned_seller_id', '=', 'u.id')
                ->whereIn('c.assigned_seller_id', $underperformers)
                ->whereIn('em.evolution_state', ['inactive', 'decreasing'])
                ->where('c.is_active', true)
                ->select([
                    'c.id as customer_id',
                    DB::raw("CONCAT(c.first_name, ' ', c.last_name) as customer_name"),
                    'c.assigned_seller_id as current_seller_id',
                    'u.full_name as current_seller_name',
                    'c.zone_id',
                    'em.evolution_state',
                    'em.last_purchase_date',
                    'em.days_since_last_purchase',
                ]);

            if ($zoneId !== null) {
                $query->where('c.zone_id', $zoneId);
            }

            return $query->orderByDesc('em.days_since_last_purchase')->get()->map(function ($r): array {
                return [
                    'customer_id'        => (string) $r->customer_id,
                    'customer_name'      => (string) $r->customer_name,
                    'current_seller_id'  => (string) $r->current_seller_id,
                    'current_seller_name' => (string) $r->current_seller_name,
                    'zone_id'            => (string) $r->zone_id,
                    'evolution_state'    => (string) $r->evolution_state,
                    'last_purchase_date' => (string) ($r->last_purchase_date ?? 'unknown'),
                    'days_since_last_purchase' => (int) ($r->days_since_last_purchase ?? 0),
                ];
            })->all();
        }

        // Fallback: customers with no sale since $since90.
        $query = DB::table('customers as c')
            ->join('users as u', 'c.assigned_seller_id', '=', 'u.id')
            ->whereIn('c.assigned_seller_id', $underperformers)
            ->where('c.is_active', true)
            ->whereNotExists(function ($sub) use ($since90): void {
                $sub->from('sales')
                    ->whereColumn('sales.customer_id', 'c.id')
                    ->whereIn('sales.status', ['delivered', 'confirmed'])
                    ->where('sales.created_at', '>=', $since90);
            })
            ->select([
                'c.id as customer_id',
                DB::raw("CONCAT(c.first_name, ' ', c.last_name) as customer_name"),
                'c.assigned_seller_id as current_seller_id',
                'u.full_name as current_seller_name',
                'c.zone_id',
            ]);

        if ($zoneId !== null) {
            $query->where('c.zone_id', $zoneId);
        }

        return $query->get()->map(function ($r): array {
            return [
                'customer_id'        => (string) $r->customer_id,
                'customer_name'      => (string) $r->customer_name,
                'current_seller_id'  => (string) $r->current_seller_id,
                'current_seller_name' => (string) $r->current_seller_name,
                'zone_id'            => (string) $r->zone_id,
                'evolution_state'    => 'inactive',
                'last_purchase_date' => 'unknown',
                'days_since_last_purchase' => 90,
            ];
        })->all();
    }

    // =========================================================================
    // LeakGuard integration
    // =========================================================================

    /**
     * Filter suggestions whose customer_id is not in the allowed input set,
     * and audit any such removal.
     *
     * @param  array<int, array<string, mixed>> $suggestions   Raw LLM suggestions.
     * @param  array<int, string>               $allowedIds    customer_ids that were in the context.
     * @return array<int, array{customer_id: string, current_seller_id: string, suggested_seller_id: string, reason: string, confidence: float}>
     */
    private function leakGuardFilter(array $suggestions, array $allowedIds, User $caller): array
    {
        $allowedLower = array_map('strtolower', $allowedIds);
        $clean        = [];

        foreach ($suggestions as $item) {
            $cid = strtolower((string) ($item['customer_id'] ?? ''));
            if (! in_array($cid, $allowedLower, true)) {
                // Foreign customer_id injected by model — audit and discard.
                try {
                    AiAudit::create([
                        'user_id'          => $caller->getKey(),
                        'customer_id'      => null,
                        'request_payload'  => null,
                        'response_payload' => $item,
                        'leak_detected'    => true,
                        'leak_details'     => "Suggestion references customer_id {$cid} not in input set.",
                    ]);
                } catch (\Throwable) {
                }
                continue;
            }

            $clean[] = [
                'customer_id'         => (string) ($item['customer_id'] ?? ''),
                'current_seller_id'   => (string) ($item['current_seller_id'] ?? ''),
                'suggested_seller_id' => (string) ($item['suggested_seller_id'] ?? ''),
                'reason'              => (string) ($item['reason'] ?? ''),
                'confidence'          => (float) ($item['confidence'] ?? 0.0),
            ];
        }

        return $clean;
    }

    // =========================================================================
    // LLM schema + prompt
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'suggestions' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'customer_id' => [
                                'type'        => 'string',
                                'description' => 'UUID of the customer to reassign. MUST be one from the reassignment_candidates list.',
                            ],
                            'current_seller_id' => [
                                'type'        => 'string',
                                'description' => 'UUID of the current assigned seller.',
                            ],
                            'suggested_seller_id' => [
                                'type'        => 'string',
                                'description' => 'UUID of the suggested new seller. Must exist in the seller_performance_table.',
                            ],
                            'reason' => [
                                'type'      => 'string',
                                'minLength' => 10,
                                'maxLength' => 600,
                                'description' => 'Justification in Spanish (Argentina), citing specific metrics.',
                            ],
                            'confidence' => [
                                'type'             => 'number',
                                'minimum'          => 0,
                                'maximum'          => 1,
                                'description'      => 'Confidence score between 0 and 1.',
                            ],
                        ],
                        'required' => ['customer_id', 'current_seller_id', 'suggested_seller_id', 'reason', 'confidence'],
                    ],
                ],
            ],
            'required' => ['suggestions'],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        Sos el asistente de gestión comercial del CRM Dermacells.

        Tu tarea: analizar la tabla de rendimiento de vendedores por zona y la lista
        de candidatos a reasignación, e identificar las reasignaciones de clientes
        que generarían mayor impacto positivo en la cartera.

        REGLAS ESTRICTAS:
        - customer_id y current_seller_id DEBEN provenir exactamente de la lista
          "reassignment_candidates". NO inventes ni modifiques UUIDs.
        - suggested_seller_id DEBE pertenecer a la tabla "seller_performance_table"
          del mismo zone_id, con mejor active_pct que el vendedor actual.
        - Nunca sugieras reasignar a un vendedor con peor desempeño que el actual.
        - El campo "reason" debe citar métricas concretas del contexto (active_pct,
          days_since_last_purchase, avg_inactive_days). En español rioplatense.
        - "confidence" refleja qué tan clara es la ventaja del vendedor sugerido.
        - No references customers or sellers not in the context.
        - Output MUST conform exactly to the structured response schema.
        PROMPT;
    }
}
