<?php

declare(strict_types=1);

namespace App\Domain\Audit\Observers;

use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CREATE-only audit observer for StockMovement.
 *
 * StockMovement is an append-only ledger (INSERT-only at the DB layer via
 * GRANT). We do not use the standard Auditable trait here because:
 *
 *   1. Movements are themselves audit records — auditing an audit table would
 *      be redundant.
 *   2. UPDATE / DELETE are revoked from app_role so those observer events
 *      will never fire in production.
 *
 * The `created` handler writes a single audit_log entry capturing the full
 * movement payload so Directors can trace every stock event via the audit log
 * viewer without needing direct access to stock_movements.
 */
class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        $this->writeAuditRow($movement);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function writeAuditRow(StockMovement $movement): void
    {
        $actor   = Auth::user();
        $request = request();

        $payload = [
            'ts'            => now()->toIso8601String(),
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_role'    => $actor?->getAttribute('role')?->value,
            'section'       => 'created',
            'entity_type'   => StockMovement::class,
            'entity_id'     => (string) $movement->getKey(),
            'field_name'    => '__created__',
            'old_value'     => null,
            'new_value'     => json_encode(
                $movement->only([
                    'movement_type', 'product_id', 'from_entity_type', 'from_entity_id',
                    'to_entity_type', 'to_entity_id', 'quantity_boxes', 'quantity_units',
                    'lot_id', 'reference_doc', 'sale_id',
                ]),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'ip_address'    => $request?->ip(),
            'session_id'    => $request?->hasSession() ? $request->session()->getId() : null,
        ];

        DB::transaction(function () use ($payload): void {
            DB::statement("SELECT pg_advisory_xact_lock(hashtextextended('audit_log_chain', 0))");

            $previous = DB::selectOne('SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1');
            $prevHash = $previous?->row_hash ?? str_repeat('0', 64);

            $key = (string) config('audit.hmac_key');

            if ($key === '') {
                Log::critical('AUDIT_HMAC_KEY missing — refusing to write StockMovement audit row');
                throw new \RuntimeException('AUDIT_HMAC_KEY is not configured.');
            }

            ksort($payload);
            $canonical = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ) ?: '{}';

            $rowHash = hash_hmac('sha256', $prevHash . $canonical, $key);

            DB::insert(
                <<<'SQL'
                    INSERT INTO audit_log (
                        occurred_at, actor_user_id, actor_role, section,
                        entity_type, entity_id, field_name, old_value, new_value,
                        ip_address, session_id, prev_hash, row_hash
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
}
