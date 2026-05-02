<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminStepUpRequired — enforces re-OAuth within the last 5 minutes for
 * sensitive Filament admin panel access (§1.8 step-up re-OAuth).
 *
 * DESIGN DECISION:
 *   AdminAccessGate (security-engineer, Phase 1) handles role + IP checks.
 *   This middleware is a separate layer that wraps AdminAccessGate at the
 *   Filament panel level and adds a temporal re-authentication requirement.
 *   We do NOT rewrite AdminAccessGate — we ADD this middleware on top.
 *
 * SESSION FLAG:
 *   admin_step_up_at (Unix timestamp) — set by the /admin/step-up callback.
 *   If absent or older than STEP_UP_TTL_SECONDS → redirect to step-up page.
 *
 * LOCAL ENV BYPASS:
 *   Skipped entirely in APP_ENV=local so developers are not blocked.
 *
 * STEP-UP FLOW:
 *   1. Middleware detects missing/expired step-up → redirects to /admin/step-up.
 *   2. StepUpController triggers OAuth re-auth flow (re-using Socialite).
 *   3. OAuth callback sets session('admin_step_up_at', now()->timestamp).
 *   4. User is redirected back to intended URL.
 *
 * The step-up OAuth route and controller are wired in routes/web.php and are
 * outside Filament's panel routes so the middleware does not create a loop.
 */
final class AdminStepUpRequired
{
    /**
     * Maximum age of a valid step-up authentication in seconds (5 minutes).
     */
    private const STEP_UP_TTL_SECONDS = 300;

    /**
     * Session key that records when the last step-up was performed.
     */
    public const SESSION_KEY = 'admin_step_up_at';

    public function handle(Request $request, Closure $next): Response
    {
        // Bypass in local dev to keep DX frictionless.
        if (app()->environment('local')) {
            return $next($request);
        }

        // The step-up page itself must not be gated — avoid infinite loop.
        if ($request->is('admin/step-up*')) {
            return $next($request);
        }

        if (! $this->hasValidStepUp($request)) {
            // Store intended URL so we can redirect back after step-up.
            $request->session()->put('admin_step_up_intended', $request->fullUrl());

            return redirect()->route('admin.step-up');
        }

        return $next($request);
    }

    private function hasValidStepUp(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        /** @var int|null $stepUpAt */
        $stepUpAt = $request->session()->get(self::SESSION_KEY);

        if ($stepUpAt === null) {
            return false;
        }

        return (time() - $stepUpAt) <= self::STEP_UP_TTL_SECONDS;
    }
}
