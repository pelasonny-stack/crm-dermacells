<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Models\AiAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LeakGuard — anti-leak post-response validator (Phase 13 — §11.5).
 *
 * After every LLM call that injected a single-customer context, the
 * response is scanned for any UUID v4 that:
 *   1. Looks like a UUID (regex match), AND
 *   2. Differs from the injected customer_id, AND
 *   3. Exists in the customers table (real foreign customer reference,
 *      not a hallucinated UUID-shaped string).
 *
 * If any such UUID is found we treat the response as compromised:
 *   - Returns LeakResult{ leaked: true, ... }
 *   - Persists ai_audit row with leak_detected = true and leak_details
 *
 * Callers SHOULD discard the response text and surface a generic error
 * to the user when leaked = true.
 */
final class LeakGuard
{
    /**
     * @var string PCRE pattern matching UUID v4 (case-insensitive)
     */
    private const UUID_V4_REGEX = '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i';

    /**
     * @param string|array<mixed> $response The LLM response (text or already-decoded JSON).
     * @param string              $injectedCustomerId The single customer UUID supplied to the model.
     * @param string|null         $userId Optional user_id for the audit row (for traceability).
     */
    public function validate(
        string|array $response,
        string $injectedCustomerId,
        ?string $userId = null,
    ): LeakResult {
        $serialised = is_array($response)
            ? (string) json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $response;

        $matches = [];
        preg_match_all(self::UUID_V4_REGEX, $serialised, $matches);
        $found = array_unique($matches[0] ?? []);

        // Eliminate the injected id (case-insensitive) — it is allowed.
        $injectedLower = strtolower($injectedCustomerId);
        $foreign = array_values(array_filter(
            $found,
            static fn (string $u): bool => strtolower($u) !== $injectedLower,
        ));

        if ($foreign === []) {
            return LeakResult::clean();
        }

        // Cross-reference against customers — only UUIDs that actually
        // resolve to a customer row are considered confirmed leaks. This
        // avoids false positives from sale_id, product_id, etc. (which
        // are legitimately included in the context).
        $confirmed = $this->confirmForeignCustomerIds($foreign);

        if ($confirmed === []) {
            return LeakResult::clean();
        }

        $details = sprintf(
            'Response referenced %d foreign customer_id(s): %s',
            count($confirmed),
            implode(', ', $confirmed),
        );

        // Persist the audit row immediately. Use try/catch — leak detection
        // should never crash the request even if the audit insert fails.
        try {
            AiAudit::create([
                'user_id'          => $userId,
                'customer_id'      => $injectedCustomerId,
                'request_payload'  => null,
                'response_payload' => is_array($response) ? $response : ['text' => $serialised],
                'leak_detected'    => true,
                'leak_details'     => $details,
            ]);
        } catch (\Throwable) {
            // Swallow — caller will still see leaked=true and abort.
        }

        return new LeakResult(true, $confirmed, $details);
    }

    /**
     * @param  array<int, string> $candidates
     * @return array<int, string>
     */
    private function confirmForeignCustomerIds(array $candidates): array
    {
        if (! Schema::hasTable('customers') || $candidates === []) {
            return [];
        }

        $existing = DB::table('customers')
            ->whereIn('id', $candidates)
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn ($id): string => (string) $id, $existing));
    }
}
