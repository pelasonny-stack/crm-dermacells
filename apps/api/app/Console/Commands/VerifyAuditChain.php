<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Walks the entire audit_log HMAC chain and verifies that every row's
 * stored row_hash is consistent with the predecessor's row_hash and the
 * canonical JSON of the row's payload.
 *
 * Per PLAN.md Phase 1.6 this command is scheduled `daily()` from
 * routes/console.php; a non-zero exit code triggers the SRE pager
 * (CloudWatch/Sentry pickup of the failure).
 *
 * Algorithm:
 *
 *   prev_hash := REPEAT('0', 64)
 *   for row in audit_log ORDER BY id ASC:
 *       expected_prev := prev_hash
 *       if row.prev_hash != expected_prev: FAIL
 *       expected_row_hash := hmac(prev_hash || canonical_json(payload), key)
 *       if row.row_hash != expected_row_hash: FAIL
 *       prev_hash := row.row_hash
 *
 * Performance: streams rows with a server-side cursor (`DB::cursor()`)
 * to avoid loading the whole log into memory. On a 10M-row partitioned
 * log this completes in single-digit minutes on the warm replica.
 *
 * Exit codes:
 *   0  chain intact
 *   1  chain mismatch detected (pager event)
 *   2  configuration problem (missing HMAC key)
 */
class VerifyAuditChain extends Command
{
    /**
     * @var string
     */
    protected $signature = 'audit:verify
                            {--from= : Optional ISO timestamp to start from (defaults to beginning of chain)}
                            {--limit= : Optional max number of rows to verify}';

    /**
     * @var string
     */
    protected $description = 'Verifies the HMAC-SHA256 chain integrity of the audit_log table (§16.13).';

    public function handle(): int
    {
        $key = (string) config('audit.hmac_key');

        if ($key === '') {
            $this->error('AUDIT_HMAC_KEY is not configured.');
            Log::critical('audit:verify aborted — AUDIT_HMAC_KEY missing');

            return 2;
        }

        $query = 'SELECT id, occurred_at, actor_user_id, actor_role, section, entity_type, entity_id, field_name, old_value, new_value, ip_address, session_id, prev_hash, row_hash FROM audit_log';
        $bindings = [];

        if (($from = $this->option('from')) !== null) {
            $query .= ' WHERE occurred_at >= ?';
            $bindings[] = $from;
        }

        $query .= ' ORDER BY id ASC';

        if (($limit = $this->option('limit')) !== null) {
            $query .= ' LIMIT ' . (int) $limit;
        }

        $expectedPrev = str_repeat('0', 64);
        $rowsChecked  = 0;

        foreach (DB::cursor($query, $bindings) as $row) {
            $rowsChecked++;

            if (! hash_equals($expectedPrev, (string) $row->prev_hash)) {
                $this->reportMismatch($row, 'prev_hash mismatch', $expectedPrev, (string) $row->prev_hash);

                return 1;
            }

            $payload = $this->payloadFromRow($row);

            $expectedRowHash = hash_hmac(
                'sha256',
                $expectedPrev . $this->canonicalJson($payload),
                $key,
            );

            if (! hash_equals($expectedRowHash, (string) $row->row_hash)) {
                $this->reportMismatch($row, 'row_hash mismatch', $expectedRowHash, (string) $row->row_hash);

                return 1;
            }

            $expectedPrev = (string) $row->row_hash;
        }

        $this->info("audit:verify OK — {$rowsChecked} rows verified.");
        Log::info('audit:verify completed without findings', ['rows' => $rowsChecked]);

        return 0;
    }

    /**
     * Reconstruct the canonical payload exactly as the AuditObserver
     * built it. Field order doesn't matter at this stage — canonicalJson()
     * sorts before encoding — but the SET of fields MUST match the
     * observer or hashes will diverge.
     *
     * @return array<string, mixed>
     */
    private function payloadFromRow(object $row): array
    {
        // Normalize occurred_at to the same ISO-8601 format the AuditObserver
        // used when computing the original row_hash (Carbon::toIso8601String()
        // outputs e.g. "2026-05-02T15:30:00+00:00"). PDO returns TIMESTAMPTZ
        // values as strings in Postgres wire format ("2026-05-02 15:30:00+00"),
        // so we parse and reformat to avoid a spurious hash mismatch.
        $ts = $row->occurred_at instanceof \DateTimeInterface
            ? $row->occurred_at->format(\DateTimeInterface::ATOM)
            : $this->normaliseTimestamp((string) $row->occurred_at);

        return [
            'ts'            => $ts,
            'actor_user_id' => $row->actor_user_id,
            'actor_role'    => $row->actor_role,
            'section'       => $row->section,
            'entity_type'   => $row->entity_type,
            'entity_id'     => $row->entity_id,
            'field_name'    => $row->field_name,
            'old_value'     => $row->old_value,
            'new_value'     => $row->new_value,
            'ip_address'    => $row->ip_address,
            'session_id'    => $row->session_id,
        ];
    }

    /**
     * Parse a Postgres TIMESTAMPTZ string and reformat it in UTC ISO-8601 format
     * to match the Carbon::toIso8601String() output the AuditObserver uses.
     *
     * Postgres returns TIMESTAMPTZ in the session timezone (which may be
     * 'America/Argentina/Buenos_Aires' = UTC-3 in this deployment), while
     * the AuditObserver uses PHP's application timezone (UTC) when writing.
     * Normalising to UTC before hashing ensures both sides agree on the string.
     *
     * Examples:
     *   "2026-05-02 13:31:21-03" → "2026-05-02T16:31:21+00:00"
     *   "2026-05-02 16:31:21+00" → "2026-05-02T16:31:21+00:00"
     */
    private function normaliseTimestamp(string $ts): string
    {
        try {
            return (new \DateTimeImmutable($ts))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            // Unparseable value — return as-is and let the hash comparison fail
            // with a clear mismatch rather than crashing the command.
            return $ts;
        }
    }

    /**
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

    private function reportMismatch(object $row, string $reason, string $expected, string $actual): void
    {
        $this->error(sprintf(
            'audit:verify FAILED at id=%s occurred_at=%s — %s (expected=%s actual=%s)',
            $row->id,
            (string) $row->occurred_at,
            $reason,
            $expected,
            $actual,
        ));

        Log::critical('audit:verify chain mismatch detected', [
            'id'          => $row->id,
            'occurred_at' => (string) $row->occurred_at,
            'reason'      => $reason,
            'expected'    => $expected,
            'actual'      => $actual,
        ]);
    }
}
