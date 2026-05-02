<?php

declare(strict_types=1);

namespace App\Domain\Audit\Observers;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Eloquent observer that captures every CREATE / UPDATE / DELETE on
 * audited models and writes one row per changed field to `audit_log`,
 * each row anchored to the previous row via an HMAC-SHA256 chain.
 *
 * Per §16.13 of the spec the audit log must be:
 *   1. Append-only at the DB layer (BEFORE UPDATE/DELETE trigger +
 *      REVOKE on app_role) — handled by the migration
 *   2. Tamper-evident at the application layer (HMAC chain) — handled here
 *
 * Chain construction:
 *   prev_hash := previous row's row_hash, OR REPEAT('0', 64) if empty
 *   row_hash  := hash_hmac('sha256',
 *                          prev_hash || canonical_json(payload),
 *                          config('audit.hmac_key'))
 *
 * The chain is verified nightly by `audit:verify` (see
 * {@see \App\Console\Commands\VerifyAuditChain}). Any break = paged
 * incident.
 *
 * Concurrency model: every insert is wrapped in a transaction with
 * `SELECT ... FOR UPDATE` on the previous row to serialize chain growth.
 * Postgres row-level locks survive in transaction-mode pgbouncer
 * because they live for the duration of the open transaction; the
 * SetPostgresRlsContext middleware already wraps the request in a
 * transaction, so this observer participates in that same transaction
 * via `DB::transaction(callable)` (which uses savepoints when nested).
 *
 * Update behaviour: one row per changed field. This satisfies §16.13's
 * "field_name, old_value, new_value" schema and gives operators fine-
 * grained timeline reconstruction at the cost of N inserts per update.
 *
 * Delete behaviour: a single row per delete with field_name = '__deleted__'
 * and a JSON snapshot of the original attributes in old_value. The
 * `deleting` hook fires before the row is gone, so getOriginal() still
 * returns the full pre-delete state.
 *
 * Bypass safety: this observer never touches audit_log via Eloquent —
 * audit_log has no model on purpose, so the immutability trigger and
 * REVOKE policy can never be sidestepped by a forgotten ::withoutEvents().
 */
class AuditObserver
{
    /**
     * Sentinel value used as field_name on the synthetic row that
     * records a model deletion.
     */
    private const FIELD_DELETED = '__deleted__';

    public function created(Model $model): void
    {
        $attributes = $this->normaliseAttributes($model->getAttributes());

        if ($attributes === []) {
            return;
        }

        foreach ($attributes as $field => $value) {
            $this->writeChainedRow(
                model: $model,
                field: (string) $field,
                oldValue: null,
                newValue: $this->stringify($value),
                eventType: 'created',
            );
        }
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();

        // Strip Laravel-managed timestamps to keep the audit log
        // operationally meaningful — we already capture occurred_at.
        unset($changes[$model->getUpdatedAtColumn() ?? 'updated_at']);

        if ($changes === []) {
            return;
        }

        foreach ($changes as $field => $newValue) {
            $oldValue = $model->getOriginal($field);

            $this->writeChainedRow(
                model: $model,
                field: (string) $field,
                oldValue: $this->stringify($oldValue),
                newValue: $this->stringify($newValue),
                eventType: 'updated',
            );
        }
    }

    public function deleting(Model $model): void
    {
        $snapshot = $this->normaliseAttributes($model->getOriginal());

        $this->writeChainedRow(
            model: $model,
            field: self::FIELD_DELETED,
            oldValue: $this->canonicalJson($snapshot),
            newValue: null,
            eventType: 'deleted',
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Writes a single audit_log row inside a transaction, locking the
     * latest row to chain off it. Raw SQL — no Eloquent model — so the
     * immutability guarantees stay enforced.
     */
    private function writeChainedRow(
        Model $model,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        string $eventType,
    ): void {
        $payload = $this->buildPayload(
            model: $model,
            field: $field,
            oldValue: $oldValue,
            newValue: $newValue,
            eventType: $eventType,
        );

        DB::transaction(function () use ($payload): void {
            // Serialise concurrent inserts via a transaction-scoped advisory
            // lock — app_role lacks UPDATE on audit_log (immutability) so
            // SELECT ... FOR UPDATE would be rejected with permission denied.
            // The advisory lock costs ~one round-trip and gives the same
            // serialisation guarantee for chain growth.
            DB::statement("SELECT pg_advisory_xact_lock(hashtextextended('audit_log_chain', 0))");

            $previous = DB::selectOne(
                'SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1'
            );

            $prevHash = $previous?->row_hash ?? str_repeat('0', 64);

            $rowHash = $this->computeRowHash($prevHash, $payload);

            DB::insert(
                <<<'SQL'
                    INSERT INTO audit_log (
                        occurred_at,
                        actor_user_id,
                        actor_role,
                        section,
                        entity_type,
                        entity_id,
                        field_name,
                        old_value,
                        new_value,
                        ip_address,
                        session_id,
                        prev_hash,
                        row_hash
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL,
                [
                    $payload['ts'],
                    $payload['actor_user_id'],
                    $payload['actor_role'],
                    $payload['section'],
                    $payload['entity_type'],
                    $payload['entity_id'],
                    $payload['field_name'],
                    $payload['old_value'],
                    $payload['new_value'],
                    $payload['ip_address'],
                    $payload['session_id'],
                    $prevHash,
                    $rowHash,
                ]
            );
        });
    }

    /**
     * Build the canonical payload that participates in the HMAC chain.
     *
     * Note: `prev_hash` and `row_hash` are NOT part of the canonical
     * payload — they bracket it. Every other auditable field is.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        Model $model,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        string $eventType,
    ): array {
        $actor = Auth::user();

        $request = request();

        return [
            'ts'            => now()->toIso8601String(),
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_role'    => $this->actorRole($actor),
            'section'       => $eventType,
            'entity_type'   => $model::class,
            'entity_id'     => (string) $model->getKey(),
            'field_name'    => $field,
            'old_value'     => $oldValue,
            'new_value'     => $newValue,
            'ip_address'    => $request?->ip(),
            'session_id'    => $request?->hasSession() ? $request->session()->getId() : null,
        ];
    }

    /**
     * HMAC-SHA256(prev_hash || canonical_json(payload), key).
     *
     * Key sourced from config('audit.hmac_key') which in production is
     * loaded from AWS Secrets Manager via the SecretsServiceProvider
     * (PLAN.md Phase 1.7).
     *
     * @param  array<string, mixed>  $payload
     */
    private function computeRowHash(string $prevHash, array $payload): string
    {
        $key = (string) config('audit.hmac_key');

        if ($key === '') {
            // Fail loudly rather than silently produce a useless chain.
            // Operations cannot recover from a key-less audit_log; better
            // to surface this on first write.
            Log::critical('AUDIT_HMAC_KEY missing — refusing to write audit row');
            throw new \RuntimeException('AUDIT_HMAC_KEY is not configured. Refusing to write to audit_log.');
        }

        return hash_hmac('sha256', $prevHash . $this->canonicalJson($payload), $key);
    }

    /**
     * Deterministic JSON encoding so the same logical payload always
     * yields the same byte sequence (key order matters to hash_hmac).
     *
     * @param  array<int|string, mixed>  $data
     */
    private function canonicalJson(array $data): string
    {
        ksort($data);

        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ) ?: '{}';
    }

    private function actorRole(?object $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        $role = $actor->getAttribute('role');

        if ($role instanceof UserRole) {
            return $role->value;
        }

        return $role === null ? null : (string) $role;
    }

    /**
     * Convert any attribute value to a string representation safe for
     * storage in TEXT columns. Arrays/objects are JSON-encoded; null
     * stays null; scalars are cast.
     */
    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * Drop attributes that should never be audited (most importantly
     * the model's own audit-trail timestamps, but also remember tokens
     * and any future password-like fields).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normaliseAttributes(array $attributes): array
    {
        unset(
            $attributes['remember_token'],
            $attributes['password'],
            $attributes['created_at'],
            $attributes['updated_at'],
        );

        return $attributes;
    }
}
