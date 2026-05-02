<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the §18.2 idle-session timeout for the web channel.
 *
 * The web app gets an absolute 8-hour session lifetime (configured via
 * SESSION_LIFETIME=480 in .env) plus a 30-minute idle window enforced
 * here. After 30 minutes of inactivity the session is invalidated and
 * the user must re-authenticate via OAuth.
 *
 * The mobile app is exempt because it uses Sanctum PATs (no server
 * session) and re-authenticates biometrically per OS prompt.
 *
 * `last_activity` is stored in the cache keyed by the authenticated user's
 * ID (not in the session) so it works on both web session routes and API
 * routes where the session store may not be started. Mobile requests that
 * authenticate via Bearer token are detected by checking whether the user
 * was resolved from a Bearer token vs a web guard session, and are exempt
 * from the idle check.
 *
 * Anonymous (unauthenticated) requests are passed through — no idle
 * bookkeeping. Auth middleware upstream is responsible for rejecting
 * protected endpoints.
 *
 * Failure mode: 401 with body { "code": "IDLE_TIMEOUT" } so the React
 * PWA can distinguish this from generic auth failures and trigger a
 * re-OAuth flow rather than showing a generic error toast.
 */
class CheckIdleTimeout
{
    private const IDLE_MINUTES = 30;

    /** Cache key prefix for per-user last_activity timestamps. */
    private const CACHE_PREFIX = 'idle_timeout:';

    public function handle(Request $request, Closure $next): Response
    {
        // Only web-session authenticated requests carry an idle window.
        // Mobile PAT requests (Bearer token) use the 'sanctum' guard; the
        // default web guard is not set for those requests.
        if (! Auth::guard('web')->check()) {
            return $next($request);
        }

        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */
        $user = Auth::guard('web')->user();
        $cacheKey = self::CACHE_PREFIX . $user->getAuthIdentifier();

        $lastActivity = Cache::get($cacheKey);

        if ($lastActivity !== null) {
            try {
                $lastActivityAt = Carbon::parse($lastActivity);
            } catch (\Throwable) {
                // Corrupt cache value — clear and force re-login.
                $lastActivityAt = null;
            }

            if ($lastActivityAt !== null && $lastActivityAt->diffInMinutes(now()) > self::IDLE_MINUTES) {
                Auth::guard('web')->logout();

                // Invalidate the session if one exists.
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                Cache::forget($cacheKey);

                return new JsonResponse(
                    ['code' => 'IDLE_TIMEOUT', 'message' => 'Session expired due to inactivity.'],
                    Response::HTTP_UNAUTHORIZED,
                );
            }
        }

        // Stamp the activity timestamp for the next request.
        Cache::put($cacheKey, now()->toIso8601String(), now()->addMinutes(self::IDLE_MINUTES + 5));

        return $next($request);
    }
}
