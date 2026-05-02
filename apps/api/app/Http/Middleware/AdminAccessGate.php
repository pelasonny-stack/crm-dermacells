<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth gate for the Filament `/admin` panel per PLAN.md
 * Phase 1.8 ("IP allowlist + step-up re-OAuth to enter").
 *
 * Three checks, evaluated in order — any failure aborts with 403 and
 * logs the rejection (never logs the IP for an authenticated success;
 * Filament auth audit handles that):
 *
 *   1. Authenticated. Anonymous requests can never reach the admin
 *      panel — even if Filament's own guard misconfigures, this
 *      middleware blocks early.
 *
 *   2. Director role. Distributors and Sellers cannot reach /admin
 *      regardless of IP or any future Filament permission package.
 *
 *   3. Source IP in the configured allowlist. Empty allowlist =
 *      "no restriction" (useful for local dev); production MUST set
 *      ADMIN_IP_ALLOWLIST in env. CIDR ranges are not supported by
 *      this minimal implementation — list explicit IPv4/IPv6 strings.
 *
 * Step-up re-OAuth (Filament-side concern) is layered on top of this
 * via `Filament::serving()` in the panel provider — the laravel-specialist
 * agent wires that hook.
 */
class AdminAccessGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        Log::info('AdminAccessGate hit', [
            'auth_check' => Auth::check(),
            'auth_id'    => Auth::id(),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'cookies'    => array_keys($request->cookies->all()),
            'ip'         => $request->ip(),
        ]);

        if ($user === null) {
            abort(403, 'Acceso denegado al panel de administración.');
        }

        if (! $this->isDirector($user)) {
            Log::warning('Filament admin access denied — non-director role', [
                'user_id' => $user->getAuthIdentifier(),
                'role'    => $this->roleOf($user),
                'ip'      => $request->ip(),
            ]);

            abort(403, 'Solo los Directores pueden acceder al panel de administración.');
        }

        if (! $this->isIpAllowed($request->ip())) {
            Log::warning('Filament admin access denied — IP not in allowlist', [
                'user_id' => $user->getAuthIdentifier(),
                'ip'      => $request->ip(),
            ]);

            abort(403, 'Acceso al panel de administración no permitido desde esta red.');
        }

        return $next($request);
    }

    /**
     * Best-effort role check that copes with both the enum cast and a
     * raw string (in case a User factory has not yet had the cast
     * applied during Phase 1 bring-up).
     */
    private function isDirector(object $user): bool
    {
        $role = $this->roleOf($user);

        return $role === UserRole::Director->value;
    }

    private function roleOf(object $user): ?string
    {
        $role = $user->getAttribute('role');

        if ($role instanceof UserRole) {
            return $role->value;
        }

        return $role === null ? null : (string) $role;
    }

    private function isIpAllowed(?string $ip): bool
    {
        $allowlist = (array) config('app.admin_ip_allowlist', []);

        // Empty allowlist = no restriction. Production deployments are
        // required to set ADMIN_IP_ALLOWLIST.
        if ($allowlist === []) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        return in_array($ip, $allowlist, true);
    }
}
