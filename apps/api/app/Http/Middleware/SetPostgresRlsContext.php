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
        // Resolve the authenticated user from any available guard.
        // For API routes (auth:sanctum), actingAs() pre-sets the sanctum guard
        // before the request is dispatched. For web routes, the session guard
        // is used. We probe both to avoid guard-ordering issues where this
        // middleware runs before the route-specific auth middleware.
        $resolvedUser = Auth::user()
            ?? (Auth::hasUser() ? null : Auth::guard('sanctum')->user());

        $userId = $resolvedUser?->getAuthIdentifier() ?? Auth::id();

        if ($userId === null) {
            return $next($request);
        }

        // Extract role from the already-loaded user model to avoid a separate
        // DB lookup on a BYPASSRLS connection (which cannot see uncommitted
        // test-transaction rows). Fall back to pgsql_migration only when the
        // user model is not yet resolved (production bootstrap path).
        if ($resolvedUser !== null && isset($resolvedUser->role)) {
            $role = $resolvedUser->role instanceof \App\Enums\UserRole
                ? $resolvedUser->role->value
                : (string) $resolvedUser->role;
        } else {
            $role = DB::connection('pgsql_migration')
                ->table('users')
                ->where('id', (string) $userId)
                ->value('role');
        }

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
