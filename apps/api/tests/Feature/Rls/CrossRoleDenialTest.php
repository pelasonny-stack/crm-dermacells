<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| RLS Cross-Role Denial Tests  (CRITICAL — Phase 1 verification)
|--------------------------------------------------------------------------
|
| Validates the hybrid Postgres RLS + SET LOCAL approach.
|
| Postgres SET LOCAL does NOT accept parameter binding, so all GUC writes
| must interpolate values directly. UUIDs are validated by format and
| roles are bounded to a small enum, both safe for inlining.
|
| Phase 1 scope: only the `users` table has RLS. Customer-level isolation
| tests will be added in Phase 3 once the customers table exists.
*/

function setRlsGuc(string $userId, string $role): void
{
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $userId));
    DB::statement(sprintf("SET LOCAL app.user_role = '%s'", $role));
}

// ---------------------------------------------------------------------------
// 1. Direct DB — manual GUC injection
// ---------------------------------------------------------------------------

it('Seller only sees their own user row when GUCs are set to seller scope', function (): void {
    $vendedorA = User::factory()->seller()->create();
    User::factory()->seller()->create();

    DB::transaction(function () use ($vendedorA): void {
        setRlsGuc($vendedorA->id, 'seller');

        $visible = User::all();

        expect($visible)->toHaveCount(1);
        expect($visible->first()->id)->toBe($vendedorA->id);
    });
});

it('Seller A cannot see Seller B row via DB when GUCs are set to Seller A scope', function (): void {
    $vendedorA = User::factory()->seller()->create();
    $vendedorB = User::factory()->seller()->create();

    DB::transaction(function () use ($vendedorA, $vendedorB): void {
        setRlsGuc($vendedorA->id, 'seller');

        $visibleIds = User::all()->pluck('id');

        expect($visibleIds)->not->toContain($vendedorB->id);
    });
});

it('Director sees all user rows when GUCs are set to director scope', function (): void {
    $director  = User::factory()->director()->create();
    $vendedorA = User::factory()->seller()->create();
    $vendedorB = User::factory()->seller()->create();

    DB::transaction(function () use ($director, $vendedorA, $vendedorB): void {
        setRlsGuc($director->id, 'director');

        $visibleIds = User::all()->pluck('id');

        expect($visibleIds)->toContain($vendedorA->id);
        expect($visibleIds)->toContain($vendedorB->id);
        expect($visibleIds)->toContain($director->id);
    });
});

it('Distributor only sees their own user row (per users_self_read policy)', function (): void {
    $distributor = User::factory()->distributor()->create();
    $vendedor    = User::factory()->seller()->create();

    DB::transaction(function () use ($distributor, $vendedor): void {
        setRlsGuc($distributor->id, 'distributor');

        $visible = User::all();
        $ids = $visible->pluck('id');

        expect($ids)->toContain($distributor->id);
        expect($ids)->not->toContain($vendedor->id);
    });
});

// ---------------------------------------------------------------------------
// 2. GUC isolation between tests
// ---------------------------------------------------------------------------

it('TestCase setUp seeds default director GUC for factory operations', function (): void {
    // setUp() in Tests\TestCase issues SET LOCAL app.user_role='director' so
    // factory inserts pass WITH CHECK predicates. Explicit per-test GUC
    // overrides happen inside nested DB::transaction blocks (savepoints).
    $role = DB::selectOne("SELECT current_setting('app.user_role', true) AS role");
    expect($role->role)->toBe('director');
});

// ---------------------------------------------------------------------------
// 3. HTTP middleware integration — DEFERRED to Phase 2
// ---------------------------------------------------------------------------
//
// These tests require a /api/v1/users endpoint that lives in Phase 2.
// Once that endpoint exists, uncomment and re-enable.
//
// it('middleware sets RLS GUCs and Seller only sees themselves via API', ...)
// it('middleware sets RLS GUCs and Director sees all users via API', ...)
