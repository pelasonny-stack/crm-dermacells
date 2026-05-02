<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SocialiteRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Handles Google and Microsoft OAuth authentication for both the React PWA
 * (web — session cookie via Sanctum SPA) and the Expo mobile app (Sanctum
 * Personal Access Token).
 *
 * Per §18.1 of the spec, login is OAuth-only — the system holds NO
 * passwords. The Director pre-registers each user's email; an OAuth login
 * for an unknown email yields a hard 403 ("Usuario no autorizado") — no
 * just-in-time provisioning under any circumstance.
 *
 * Tenant restriction (§18 + PLAN.md Phase 1.5):
 *   - google:    `hd` claim (hosted domain) must match an entry in the
 *                GOOGLE_HD_ALLOWLIST env var (config('auth.tenants.google'))
 *   - microsoft: `tid` claim (tenant id GUID) must match an entry in the
 *                MICROSOFT_TID_ALLOWLIST env var (config('auth.tenants.microsoft'))
 *
 * Empty allowlists mean "no restriction" — useful for local development;
 * production deployments MUST set these allowlists.
 *
 * On first successful OAuth login the user row is bound to (oauth_provider,
 * oauth_sub). Subsequent logins assert that the bound sub still matches —
 * this prevents an attacker who controls a user's email from hijacking the
 * account by forcing an OAuth re-bind from a different provider account.
 *
 * Web flow: the regular Socialite redirect/callback dance with session
 * cookies. Auth::login() is called with `remember: true` to seed the
 * session; CheckIdleTimeout middleware enforces the 30-minute idle window
 * defined in §18.2. The browser-side Sanctum SPA cookie covers subsequent
 * API calls.
 *
 * Mobile flow: the Expo client uses expo-auth-session
 * (ASWebAuthenticationSession / Custom Tabs) to drive the OAuth dance,
 * receives the authorization `code`, and POSTs `{provider, code, state,
 * code_verifier, device_id}` to /api/v1/auth/oauth/{provider} with the
 * `X-Client-Type: mobile` header (or `?client=mobile`). The backend
 * rebuilds a stateless Socialite request, exchanges the code, and issues
 * a Sanctum PAT scoped to that device with the role's abilities and a
 * 30-day TTL. The token plain text is returned ONCE — the mobile client
 * stores it in `expo-secure-store` per §18.3.
 */
class OAuthController extends Controller
{
    /**
     * Map of supported providers → friendly identifier strings used in
     * abort messages and audit log lines. Anything not in this map is
     * rejected before Socialite is invoked.
     */
    private const SUPPORTED_PROVIDERS = ['google', 'microsoft'];

    /**
     * Step 1 (web): redirect the browser to the OAuth provider's
     * authorization endpoint.
     *
     * For mobile, the redirect URL is built client-side by expo-auth-session;
     * this endpoint is not called from the mobile flow.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->assertSupportedProvider($provider);

        $isMobile = $this->isMobileRequest($request);

        return SocialiteRegistrar::driver($provider, stateless: $isMobile)
            ->redirect();
    }

    /**
     * Step 2: handle the provider's callback for both web and mobile.
     *
     * Mobile clients hit POST /api/v1/auth/oauth/{provider} with
     * `X-Client-Type: mobile`; web clients hit GET /auth/{provider}/callback
     * via the redirect above.
     *
     * @return JsonResponse|RedirectResponse  JSON `{token, user}` for mobile,
     *                                         RedirectResponse for web
     */
    public function callback(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        $this->assertSupportedProvider($provider);

        $isMobile = $this->isMobileRequest($request);

        try {
            $oauthUser = SocialiteRegistrar::driver($provider, stateless: $isMobile)
                ->user();
        } catch (\Throwable $e) {
            Log::warning('OAuth callback failed to resolve user', [
                'provider'  => $provider,
                'is_mobile' => $isMobile,
                'error'     => $e->getMessage(),
            ]);

            abort(401, 'No se pudo completar la autenticación con ' . ucfirst($provider));
        }

        $tenantError = $this->assertTenantAllowed($provider, $oauthUser);

        if ($tenantError !== null) {
            return $tenantError;
        }

        $email = strtolower((string) $oauthUser->getEmail());

        if ($email === '') {
            return response()->json(['code' => 'OAUTH_EMAIL_MISSING', 'message' => 'El proveedor OAuth no devolvió un email.'], Response::HTTP_UNAUTHORIZED);
        }

        // Pre-registered email lookup — no JIT provisioning per §18.1.
        // We look up by email first (regardless of is_active) so we can
        // distinguish "not registered" from "registered but inactive".
        $userByEmail = User::query()->where('email', $email)->first();

        if ($userByEmail === null) {
            Log::info('OAuth login rejected — email not pre-registered', [
                'provider' => $provider,
                'email'    => $email,
            ]);

            return response()->json(['code' => 'OAUTH_EMAIL_NOT_REGISTERED', 'message' => 'Usuario no autorizado.'], Response::HTTP_FORBIDDEN);
        }

        if (! (bool) $userByEmail->getAttribute('is_active')) {
            Log::info('OAuth login rejected — user is inactive', [
                'provider' => $provider,
                'email'    => $email,
            ]);

            return response()->json(['code' => 'OAUTH_USER_INACTIVE', 'message' => 'La cuenta de usuario está desactivada.'], Response::HTTP_FORBIDDEN);
        }

        $user = $userByEmail;

        $subError = $this->bindOrAssertOAuthSubject($user, $provider, $oauthUser);

        if ($subError !== null) {
            return $subError;
        }

        if ($isMobile) {
            return $this->issueMobileToken($request, $user);
        }

        return $this->loginWebSession($request, $user);
    }

    /**
     * Web logout: invalidate the session and regenerate the CSRF token.
     *
     * Mobile clients call {@see revokeToken()} instead — different
     * mechanics, same effect (immediate access loss).
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Mobile logout: revoke the current personal access token only.
     *
     * Other devices stay logged in — to nuke every PAT for the user,
     * call `$user->tokens()->delete()` from the Filament admin (§18.4).
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['revoked' => true]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Throws 404 for unknown providers (invalid route segment).
     */
    private function assertSupportedProvider(string $provider): void
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            abort(404, "OAuth provider '{$provider}' no soportado");
        }
    }

    /**
     * Detects mobile clients via either the `X-Client-Type` header or the
     * `?client=mobile` query string fallback (useful when a CDN strips
     * custom headers in development).
     */
    private function isMobileRequest(Request $request): bool
    {
        return strtolower((string) $request->header('X-Client-Type')) === 'mobile'
            || strtolower((string) $request->query('client')) === 'mobile'
            || strtolower((string) $request->query('mode')) === 'mobile';
    }

    /**
     * Enforces the per-provider tenant allowlist.
     *
     * - google:    asserts the `hd` (hosted domain) raw claim is in the
     *              configured allowlist
     * - microsoft: asserts the `tid` (tenant id GUID) raw claim is in the
     *              configured allowlist
     *
     * An empty allowlist is treated as "no restriction" so local dev
     * works without env config; production deployments are required by
     * the security review to set these allowlists.
     *
     * Returns a JsonResponse on failure, or null if the tenant is allowed.
     */
    private function assertTenantAllowed(string $provider, SocialiteUser $oauthUser): ?JsonResponse
    {
        $allowlist = (array) config("auth.tenants.{$provider}", []);

        if ($allowlist === []) {
            return null;
        }

        $raw = method_exists($oauthUser, 'getRaw') ? $oauthUser->getRaw() : [];

        $tenantValue = match ($provider) {
            'google'    => $raw['hd']  ?? null,
            'microsoft' => $raw['tid'] ?? null,
            default     => null,
        };

        if ($tenantValue === null || ! in_array($tenantValue, $allowlist, true)) {
            Log::warning('OAuth tenant restriction blocked login', [
                'provider'      => $provider,
                'tenant_value'  => $tenantValue,
                'email'         => method_exists($oauthUser, 'getEmail') ? $oauthUser->getEmail() : null,
            ]);

            $code = match ($provider) {
                'google'    => 'OAUTH_HD_NOT_ALLOWED',
                'microsoft' => 'OAUTH_TID_NOT_ALLOWED',
                default     => 'OAUTH_TENANT_NOT_ALLOWED',
            };

            return response()->json(
                ['code' => $code, 'message' => 'Su organización no tiene autorización para acceder al sistema.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    /**
     * On first successful login, store (oauth_provider, oauth_sub) on the
     * user row. On subsequent logins, assert the bound sub still matches —
     * any mismatch means someone else's identity at the same provider is
     * trying to claim this email (or the user re-created their provider
     * account, in which case a Director must reset the binding manually).
     *
     * Returns null on success, or a JsonResponse on failure.
     */
    private function bindOrAssertOAuthSubject(User $user, string $provider, SocialiteUser $oauthUser): ?JsonResponse
    {
        $sub = (string) $oauthUser->getId();

        if ($sub === '') {
            return response()->json(['code' => 'OAUTH_SUB_MISSING', 'message' => 'El proveedor OAuth no devolvió un identificador de usuario.'], Response::HTTP_UNAUTHORIZED);
        }

        $boundSub = $user->getAttribute('oauth_sub');

        if ($boundSub === null) {
            $user->forceFill([
                'oauth_provider' => $provider,
                'oauth_sub'      => $sub,
            ])->save();

            return null;
        }

        $boundProvider = $user->getAttribute('oauth_provider');

        if ($boundProvider !== $provider || ! hash_equals((string) $boundSub, $sub)) {
            Log::warning('OAuth subject mismatch — possible identity takeover attempt', [
                'user_id'           => $user->getKey(),
                'bound_provider'    => $boundProvider,
                'attempted_provider'=> $provider,
            ]);

            return response()->json(['code' => 'OAUTH_SUB_MISMATCH', 'message' => 'La identidad OAuth no coincide con el registro original. Contacte a un Director.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Issues a Sanctum Personal Access Token for the mobile flow.
     *
     * Token name is `expo:{deviceId}` so each device shows up distinctly
     * in the Filament token-management UI (Phase 16). TTL is 30 days per
     * §18.3; abilities are derived from the user's role enum so a
     * compromised token is naturally scoped.
     */
    private function issueMobileToken(Request $request, User $user): JsonResponse
    {
        $deviceId = (string) ($request->input('device_id') ?? $request->header('X-Device-Id') ?? 'unknown-device');

        $abilities = $this->resolveAbilities($user);

        $token = $user->createToken(
            "expo:{$deviceId}",
            $abilities,
            now()->addDays(30),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => [
                'id'       => $user->getKey(),
                'email'    => $user->getAttribute('email'),
                'name'     => $user->getAttribute('full_name') ?? $user->getAttribute('name'),
                'role'     => $user->getAttribute('role'),
                'can_sell' => (bool) $user->getAttribute('can_sell'),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Logs the user into the session-based web guard and returns a JSON
     * 200 response so the React SPA can detect success and navigate
     * client-side. A traditional server-side redirect to '/' is avoided
     * because the SPA controls routing after OAuth completes.
     *
     * remember: false — the users table has no remember_token column
     * (OAuth-only login; session lifetime governed by SESSION_LIFETIME
     * + CheckIdleTimeout enforcing the 30-minute idle window).
     */
    private function loginWebSession(Request $request, User $user): JsonResponse
    {
        Auth::login($user, remember: false);

        $request->session()->regenerate();
        $request->session()->put('last_activity', now()->toIso8601String());

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id'    => $user->getKey(),
                'email' => $user->getAttribute('email'),
                'role'  => $user->getAttribute('role'),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Resolves Sanctum token abilities from the user's role.
     *
     * Falls back to a single wildcard ability when the role enum hasn't
     * yet defined an abilities() helper — this keeps the controller
     * working before the Permissions phase lands. The laravel-specialist
     * agent owns the final ability list.
     *
     * @return array<int, string>
     */
    private function resolveAbilities(User $user): array
    {
        $role = $user->getAttribute('role');

        if (is_object($role) && method_exists($role, 'abilities')) {
            $abilities = $role->abilities();

            if (is_array($abilities) && $abilities !== []) {
                return array_values(array_map('strval', $abilities));
            }
        }

        return ['*'];
    }
}
