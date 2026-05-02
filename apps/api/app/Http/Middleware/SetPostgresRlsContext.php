<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activates PostgreSQL Row Level Security GUCs for the duration of a request.
 *
 * Every request that runs as an authenticated user is wrapped in a database
 * transaction so that `SET LOCAL` statements persist for the entire request
 * lifecycle. `SET LOCAL` reverts automatically when the transaction ends,
 * which prevents GUC leakage across pooled connections (Pgbouncer transaction
 * mode).
 *
 * Unauthenticated requests are passed through without opening a transaction;
 * auth middleware upstream will reject them before any RLS-protected table is
 * touched.
 *
 * @see https://laravel.com/docs/12.x/database#database-transactions
 * @see https://www.postgresql.org/docs/16/ddl-rowsecurity.html
 */
class SetPostgresRlsContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Use Auth::id() instead of Auth::user() — the latter would call
        // User::find() under app_role with no GUC set, which RLS hides.
        // Chicken-and-egg: we need the GUC set BEFORE the user model can
        // be loaded.
        $userId = Auth::id();

        if ($userId === null) {
            return $next($request);
        }

        // Look up role via migration_role connection (BYPASSRLS) to avoid
        // the same chicken-and-egg. Cache on the request to avoid repeat
        // queries within the same request.
        $role = DB::connection('pgsql_migration')
            ->table('users')
            ->where('id', (string) $userId)
            ->value('role');

        if ($role === null) {
            return $next($request);
        }

        // Defense in depth: fail closed if values look unexpected. Postgres
        // SET LOCAL does not accept parameter binding.
        if (! preg_match('/^[0-9a-f-]{36}$/i', (string) $userId)) {
            throw new \RuntimeException('Invalid user id format for RLS context');
        }
        if (! in_array($role, ['director', 'distributor', 'seller'], true)) {
            throw new \RuntimeException('Invalid user role for RLS context');
        }

        return DB::transaction(function () use ($request, $next, $userId, $role): Response {
            DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $userId));
            DB::statement(sprintf("SET LOCAL app.user_role = '%s'", $role));

            return $next($request);
        });
    }
}
