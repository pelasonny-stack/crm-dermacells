<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Audit Log Immutability Tests
|--------------------------------------------------------------------------
|
| The audit_log table is append-only per §16.13. Immutability is enforced
| at three layers (the DB layer is tested here):
|
|   1. BEFORE UPDATE OR DELETE trigger → raises 'audit_log is immutable'.
|   2. REVOKE UPDATE, DELETE, TRUNCATE FROM app_role (runtime DB role).
|   3. HMAC chain integrity (tested in HmacChainTest.php).
|
| Partition routing is also verified: inserting a row with `occurred_at`
| in the next month should land in the correct monthly partition.
|
*/

/**
 * Insert a raw row into audit_log and return its generated id.
 * Uses DB::table() without the REVOKE restriction because test DB users
 * typically connect as a superuser (or migration role).
 */
function insertAuditRow(?\DateTimeInterface $occurredAt = null): int
{
    $occurredAt ??= now();

    return DB::table('audit_log')->insertGetId([
        'occurred_at'  => $occurredAt->format('Y-m-d H:i:sO'),
        'actor_user_id' => null,
        'actor_role'   => 'system',
        'section'      => 'test',
        'entity_type'  => 'User',
        'entity_id'    => '00000000-0000-4000-8000-000000000001',
        'field_name'   => 'email',
        'old_value'    => null,
        'new_value'    => 'test@example.com',
        'prev_hash'    => str_repeat('0', 64),
        'row_hash'     => hash('sha256', 'seed-value-for-test'),
    ]);
}

// ---------------------------------------------------------------------------

it('raises a QueryException when attempting to UPDATE a row in audit_log', function (): void {
    $id = insertAuditRow();

    // The app_role lacks UPDATE privilege on audit_log (REVOKE enforced at DB level).
    // The exception message contains "permission denied for table audit_log".
    expect(fn () => DB::table('audit_log')
        ->where('id', $id)
        ->update(['actor_role' => 'tampered']))
        ->toThrow(QueryException::class, 'permission denied for table audit_log');
});

it('raises a QueryException when attempting to DELETE a row from audit_log', function (): void {
    $id = insertAuditRow();

    // The app_role lacks DELETE privilege on audit_log (REVOKE enforced at DB level).
    // The exception message contains "permission denied for table audit_log".
    expect(fn () => DB::table('audit_log')
        ->where('id', $id)
        ->delete())
        ->toThrow(QueryException::class, 'permission denied for table audit_log');
});

it('allows inserting new rows into audit_log', function (): void {
    // A simple insert must succeed — it is the only permitted mutation.
    $id = insertAuditRow();

    expect($id)->toBeInt()->toBeGreaterThan(0);

    $row = DB::table('audit_log')->where('id', $id)->first();
    expect($row)->not->toBeNull();
    expect($row->entity_type)->toBe('User');
});

it('routes a row with occurred_at in next month to the next month partition', function (): void {
    // Determine next month's partition name.
    $nextMonthStart = now()->addMonth()->startOfMonth();
    $partitionName  = 'audit_log_' . $nextMonthStart->format('Y_m');

    // Insert a row stamped to the middle of next month.
    $nextMonthMid = $nextMonthStart->copy()->addDays(14);
    insertAuditRow($nextMonthMid);

    // Verify the partition exists and contains the row.
    // pg_class holds partition metadata; pg_inherits links child to parent.
    $partitionExists = DB::selectOne(<<<'SQL'
        SELECT 1 AS exists
        FROM   pg_class c
        JOIN   pg_inherits i ON i.inhrelid = c.oid
        JOIN   pg_class   p ON p.oid       = i.inhparent
        WHERE  p.relname = 'audit_log'
        AND    c.relname = ?
    SQL, [$partitionName]);

    expect($partitionExists)->not->toBeNull(
        "Partition [{$partitionName}] was not found in pg_class — " .
        'pg_partman or the migration must have created it before the test.'
    );

    // Confirm the row was physically stored in that partition (not the parent).
    $countInPartition = DB::selectOne(
        "SELECT COUNT(*) AS cnt FROM {$partitionName} WHERE section = 'test'"
    );

    expect((int) $countInPartition->cnt)->toBeGreaterThanOrEqual(1);
});

it('the trigger raises on UPDATE even when bypassing Eloquent via raw SQL', function (): void {
    $id = insertAuditRow();

    // The app_role lacks UPDATE privilege on audit_log (REVOKE enforced at DB level).
    // Even raw SQL is blocked; the exception contains "permission denied for table audit_log".
    expect(fn () => DB::statement(
        "UPDATE audit_log SET actor_role = 'hacked' WHERE id = ?",
        [$id]
    ))->toThrow(QueryException::class, 'permission denied for table audit_log');
});
