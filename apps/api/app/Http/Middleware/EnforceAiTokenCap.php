<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\AiUserOverride;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforceAiTokenCap — request-time guard for the AI module
 * (Phase 13 — §11.1 + §11.5 verification: switch global / per-user override / cap exceeded).
 *
 * Order of checks (each short-circuits the rest):
 *   1. Authenticated.                        → 401 by upstream auth middleware otherwise
 *   2. AiSetting::current()->global_enabled  → 503 AI_GLOBALLY_DISABLED
 *   3. Per-user override.enabled === false   → 403 AI_DISABLED_FOR_USER
 *      (Directors are exempt — see §11.1)
 *   4. monthly token usage >= cap            → 429 AI_TOKEN_CAP_EXCEEDED
 *
 * Both the cap and the override are NULL-able; resolution uses COALESCE
 * against AiSetting::monthly_token_cap_default.
 */
final class EnforceAiTokenCap
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user === null) {
            // Upstream auth:sanctum should have rejected; bail with 401 for safety.
            return $this->problem(401, 'AI_AUTH_REQUIRED', 'Authentication required.');
        }

        // 1. Global switch
        $settings = AiSetting::current();
        if (! $settings->global_enabled) {
            return $this->problem(503, 'AI_GLOBALLY_DISABLED', 'The AI Assistant module is globally disabled.');
        }

        // 2. Per-user override (Directors exempt).
        $isDirector = $this->isDirector($user);
        $override   = AiUserOverride::find($user->getKey());

        if (! $isDirector && $override !== null && $override->enabled === false) {
            return $this->problem(403, 'AI_DISABLED_FOR_USER', 'The AI Assistant is disabled for this user.');
        }

        // 3. Token cap
        $cap = $override?->monthly_token_cap ?? $settings->monthly_token_cap_default;

        $periodMonth = Carbon::now()->startOfMonth()->toDateString();
        $used = (int) AiUsage::query()
            ->where('user_id', $user->getKey())
            ->where('period_month', $periodMonth)
            ->sum('total_tokens');

        if ($used >= $cap) {
            return $this->problem(
                429,
                'AI_TOKEN_CAP_EXCEEDED',
                sprintf('Monthly AI token cap (%d) exceeded — used %d this month.', $cap, $used),
            );
        }

        return $next($request);
    }

    private function isDirector(object $user): bool
    {
        $role = $user->getAttribute('role');
        if ($role instanceof UserRole) {
            return $role === UserRole::Director;
        }
        return $role === UserRole::Director->value;
    }

    private function problem(int $status, string $code, string $detail): Response
    {
        return response()->json([
            'type'   => "https://crm.dermacells.com/problems/{$code}",
            'title'  => $code,
            'status' => $status,
            'detail' => $detail,
            'code'   => $code,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}
