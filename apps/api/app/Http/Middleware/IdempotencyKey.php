<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey as IdempotencyKeyModel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Idempotency-Key middleware — Stripe pattern, 24h TTL (Phase 0 spec).
 *
 * Header: `Idempotency-Key: <uuid-v4>`
 *
 * FLOW:
 *   1. Read `Idempotency-Key` header. If missing or not a valid UUID v4 → 422.
 *   2. Hash raw request body with SHA-256.
 *   3. Look up (user_id, key) in idempotency_keys.
 *      a. Row found + body hash matches + response stored → replay (200/201/etc) with
 *         `Idempotency-Replayed: true` header. Response status taken from stored value.
 *      b. Row found + body hash DIFFERS → 422 IDEMPOTENCY_KEY_BODY_MISMATCH.
 *      c. Row found but response not yet stored (concurrent in-flight request) → 409.
 *      d. No row → forward request, persist response afterwards.
 *   4. After action: UPDATE the row with response_body + response_status.
 *
 * The middleware is registered under the alias 'idempotent' in bootstrap/app.php.
 * Apply it to POST /sales, POST /payments, POST /invoices.
 *
 * NOTE: This middleware requires the user to be authenticated (Auth::user() must
 * exist). It should be placed after the 'auth:sanctum' middleware in the chain.
 */
final class IdempotencyKey
{
    public function handle(Request $request, Closure $next): BaseResponse
    {
        $rawKey = $request->header('Idempotency-Key');

        // 1. Validate header presence and UUID v4 format
        if (! $rawKey) {
            return $this->problem(
                422,
                'IDEMPOTENCY_KEY_REQUIRED',
                'The Idempotency-Key header is required for this endpoint.',
            );
        }

        if (! $this->isValidUuidV4($rawKey)) {
            return $this->problem(
                422,
                'IDEMPOTENCY_KEY_INVALID',
                'The Idempotency-Key header must be a valid UUID v4.',
            );
        }

        $userId  = Auth::id();
        $bodyHash = $this->hashBody($request->getContent());

        // 2. Look up existing record
        /** @var IdempotencyKeyModel|null $record */
        $record = IdempotencyKeyModel::where('user_id', $userId)
            ->where('key', $rawKey)
            ->first();

        if ($record) {
            // Expired records are treated as non-existent (let the request through)
            if ($record->isExpired()) {
                $record->delete();
            } elseif ($record->request_body_hash !== $bodyHash) {
                // b. Same key, different body → mismatch error
                return $this->problem(
                    422,
                    'IDEMPOTENCY_KEY_BODY_MISMATCH',
                    'The Idempotency-Key was used with a different request body.',
                );
            } elseif (! $record->hasResponse()) {
                // c. In-flight concurrent request with same key
                return $this->problem(
                    409,
                    'IDEMPOTENCY_KEY_IN_FLIGHT',
                    'A request with this Idempotency-Key is already being processed.',
                );
            } else {
                // a. Replay cached response
                return $this->replay($record);
            }
        }

        // 3. Create the key record (no response yet — marks the request as in-flight)
        $record = IdempotencyKeyModel::create([
            'user_id'           => $userId,
            'key'               => $rawKey,
            'request_path'      => $request->path(),
            'request_body_hash' => $bodyHash,
            'response_body'     => null,
            'response_status'   => null,
            'created_at'        => now(),
            'expires_at'        => now()->addHours(24),
        ]);

        // 4. Forward request
        /** @var BaseResponse $response */
        $response = $next($request);

        // 5. Persist response (only on successful/client-error status, not 5xx)
        if ($response->getStatusCode() < 500) {
            $record->update([
                'response_body'   => $response->getContent(),
                'response_status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isValidUuidV4(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
    }

    private function hashBody(string $body): string
    {
        return hash('sha256', $body);
    }

    private function replay(IdempotencyKeyModel $record): BaseResponse
    {
        $responseBody   = $record->response_body ?? '{}';
        $responseStatus = $record->response_status ?? 200;

        return response($responseBody, $responseStatus)
            ->header('Content-Type', 'application/json')
            ->header('Idempotency-Replayed', 'true');
    }

    /**
     * RFC 7807 problem+json error response.
     */
    private function problem(int $status, string $code, string $detail): BaseResponse
    {
        return response()->json([
            'type'   => "https://crm.dermacells.com/problems/{$code}",
            'title'  => $code,
            'status' => $status,
            'detail' => $detail,
            'code'   => $code,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}
