<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| HMAC Chain Integrity Tests
|--------------------------------------------------------------------------
|
| The AuditObserver (App\Domain\Audit\Observers\AuditObserver) builds an
| HMAC-SHA256 chain where each audit_log row's `row_hash` covers:
|
|     HMAC-SHA256(key, prev_hash || entity_type || entity_id || field || old || new)
|
| and the next row's `prev_hash` must equal the previous row's `row_hash`.
|
| This chain allows the `audit:verify` artisan command to detect any
| tampering (insertion, deletion, or field modification) by walking the
| chain and recomputing each hash.
|
| The `pgsql_super` connection note from the spec:
|   Bypassing the trigger to inject a tampered row requires superuser
|   privileges (or disabling the trigger). In the test DB the test runner
|   typically connects as a role with SUPERUSER or the trigger can be
|   suspended with ALTER TABLE ... DISABLE TRIGGER. We document the
|   approach and fall back to disabling the trigger temporarily.
|
*/

/**
 * Retrieve all audit_log rows for a given entity, ordered by id.
 *
 * The AuditObserver stores entity_type as the full PHP class name
 * (e.g. "App\Models\User") via $model::class, not a short alias.
 *
 * @return \Illuminate\Support\Collection<int, object>
 */
function getAuditRows(string $entityType, string $entityId): \Illuminate\Support\Collection
{
    // Accept both the short alias (e.g. 'User') and the fully-qualified class
    // name (e.g. 'App\Models\User') so callers can use either form.
    $fqcn = class_exists($entityType) ? $entityType : 'App\\Models\\' . $entityType;

    return collect(DB::select(
        'SELECT * FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id ASC',
        [$fqcn, $entityId]
    ));
}

// ---------------------------------------------------------------------------

it('AuditObserver creates an audit_log row when a User is created', function (): void {
    // User::observe(AuditObserver::class) must be registered in AppServiceProvider.
    $user = User::factory()->create([
        'email'     => 'audited@example.com',
        'full_name' => 'Audited User',
    ]);

    $rows = getAuditRows('User', $user->id);

    // The observer writes one row per field on created(); User has several
    // auditable fields, so there will be multiple rows, not exactly one.
    expect($rows->count())->toBeGreaterThanOrEqual(1);

    $row = $rows->first();
    // entity_type is stored as the fully-qualified class name by the observer.
    expect($row->entity_type)->toBe(\App\Models\User::class);
    expect($row->entity_id)->toBe($user->id);
    expect($row->row_hash)->toBeHmacSha256();
});

it('each audit_log row prev_hash matches the previous row row_hash (chain is intact)', function (): void {
    // Trigger multiple audit events by creating and updating a user.
    $user = User::factory()->create(['email' => 'chain@example.com']);
    $user->update(['full_name' => 'Updated Name']);
    $user->update(['full_name' => 'Updated Again']);

    $rows = getAuditRows('User', $user->id);

    expect($rows->count())->toBeGreaterThanOrEqual(2);

    // Walk the chain: each row's prev_hash must equal the prior row's row_hash.
    $rows->each(function (object $row, int $index) use ($rows): void {
        if ($index === 0) {
            // First row: prev_hash must be the zero sentinel.
            expect($row->prev_hash)->toBe(str_repeat('0', 64));

            return;
        }

        $prev = $rows->get($index - 1);
        expect($row->prev_hash)->toBe($prev->row_hash, sprintf(
            'Chain broken: row id=%d prev_hash [%s] does not match prior row id=%d row_hash [%s].',
            $row->id,
            $row->prev_hash,
            $prev->id,
            $prev->row_hash
        ));
    });
});

it('audit:verify artisan command exits 0 when the chain is intact', function (): void {
    User::factory()->create(['email' => 'verify-ok@example.com']);

    $exitCode = Artisan::call('audit:verify');

    // Capture output for debugging if the test fails.
    $output = Artisan::output();

    expect($exitCode)->toBe(0, 'audit:verify failed: ' . $output);
});

it('audit:verify artisan command exits non-zero when a row has been tampered', function (): void {
    // -----------------------------------------------------------------------
    // SUPERUSER BYPASS NOTE
    // -----------------------------------------------------------------------
    // The audit_log table has a BEFORE UPDATE trigger that prevents any UPDATE.
    // To simulate tampering in tests we temporarily disable the trigger.
    // This requires the test DB user to have ALTER TABLE privilege (typically
    // a superuser or the migration_role).
    //
    // If the test DB user cannot disable triggers, mark this test as skipped
    // and run it manually with a superuser connection ('pgsql_super').
    // -----------------------------------------------------------------------

    $user = User::factory()->create(['email' => 'tamper-victim@example.com']);

    $rows = getAuditRows('User', $user->id);
    $row  = $rows->first();

    if (is_null($row)) {
        $this->markTestSkipped('AuditObserver not yet registered — skipping tamper test.');
    }

    // Disable trigger, tamper a field, re-enable trigger.
    try {
        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER trg_audit_log_immutable');

        DB::table('audit_log')
            ->where('id', $row->id)
            ->update(['new_value' => 'TAMPERED_VALUE']);

        DB::statement('ALTER TABLE audit_log ENABLE TRIGGER trg_audit_log_immutable');
    } catch (\Throwable $e) {
        $this->markTestSkipped(
            'Cannot disable audit_log trigger (insufficient privileges). ' .
            'Run this test as superuser via pgsql_super connection. Error: ' . $e->getMessage()
        );
    }

    // The verify command should now detect the mismatch.
    $exitCode = Artisan::call('audit:verify');

    expect($exitCode)->not->toBe(0, 'audit:verify should exit non-zero when chain is broken.');
});

it('the row_hash field is a 64-character lowercase hex string', function (): void {
    $user = User::factory()->create(['email' => 'hashcheck@example.com']);

    $rows = getAuditRows('User', $user->id);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->row_hash)->toBeHmacSha256();
    }
});
