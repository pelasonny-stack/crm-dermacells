<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Provides RLS context activation for Horizon Jobs that touch RLS-protected
 * tables.
 *
 * Because queue workers run outside of a normal HTTP request/response cycle,
 * the {@see \App\Http\Middleware\SetPostgresRlsContext} middleware does not
 * apply. Any Job that reads or writes RLS-protected tables must call
 * {@see withRlsContext()} in its `handle()` method, passing the serialized
 * `actingUserId` and `role` from the job payload.
 *
 * The callback is wrapped in a database transaction so that `SET LOCAL`
 * persists for the entire duration of the callable, then reverts when the
 * transaction commits — preventing GUC leakage across pooled connections
 * (Pgbouncer transaction mode).
 *
 * Usage in a Job:
 *
 * ```php
 * public function handle(): void
 * {
 *     $this->withRlsContext($this->actingUserId, $this->actingRole, function () {
 *         // queries here run with RLS GUCs set
 *     });
 * }
 * ```
 *
 * @see https://laravel.com/docs/12.x/database#database-transactions
 * @see https://www.postgresql.org/docs/16/ddl-rowsecurity.html
 */
trait SetsRlsContext
{
    /**
     * Execute a callable inside a database transaction with PostgreSQL RLS
     * GUCs (`app.user_id` and `app.user_role`) set for the given user.
     *
     * Returns whatever the callable returns, preserving full type information
     * for the caller.
     *
     * @template TReturn
     *
     * @param  string    $userId  UUID of the acting user (serialized in the job payload)
     * @param  string    $role    Role value string (e.g. 'director', 'distributor', 'seller')
     * @param  callable(): TReturn  $fn  The work to execute under RLS context
     * @return TReturn
     */
    protected function withRlsContext(string $userId, string $role, callable $fn): mixed
    {
        return DB::transaction(function () use ($userId, $role, $fn): mixed {
            // Postgres SET LOCAL does not support parameter binding — interpolate
            // safely after format validation.
            if (! preg_match('/^[0-9a-f-]{36}$/i', $userId)) {
                throw new \RuntimeException('Invalid user id format for RLS context');
            }
            if (! in_array($role, ['director', 'distributor', 'seller'], true)) {
                throw new \RuntimeException('Invalid user role for RLS context');
            }

            DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $userId));
            DB::statement(sprintf("SET LOCAL app.user_role = '%s'", $role));

            return $fn();
        });
    }
}
