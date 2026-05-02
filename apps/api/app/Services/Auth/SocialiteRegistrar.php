<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Event;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * Centralises Socialite provider configuration for Dermacells.
 *
 * Two providers are supported:
 *
 *   - google     (built into Laravel Socialite — supports PKCE via
 *                 `setPkceCode()` on the underlying OAuth client; no extra
 *                 driver registration required)
 *   - microsoft  (provided by socialiteproviders/microsoft — requires the
 *                 SocialiteProviders event listener pattern, registered
 *                 via {@see registerEventListener()})
 *
 * Per OAuth 2.1 BCP §6.1 (PLAN.md Phase 0 decisions), no refresh tokens
 * are issued — clients re-OAuth on 401. PKCE is therefore the primary
 * defence against authorization code interception, especially on the
 * Expo mobile client.
 *
 * For Google: Socialite's built-in `GoogleProvider` exposes
 * `setPkceCode($verifier)` and `getPkceCode()`. The expo-auth-session
 * library on the mobile side generates the code_verifier/code_challenge
 * pair and forwards both to the backend. The backend echoes the
 * code_verifier when exchanging the authorization code at the token
 * endpoint. No backend driver override is required for Google.
 *
 * For Microsoft: the SocialiteProviders package handles the multi-tenant
 * OAuth dance, including `tid` claim extraction. PKCE is enabled by
 * default in v4.x of the package. The bound `tenant_id` parameter on the
 * authorize URL ('common' or a specific tenant GUID) is configured in
 * `config/services.php`.
 *
 * @see https://laravel.com/docs/12.x/socialite
 * @see https://socialiteproviders.com/Microsoft/
 * @see https://datatracker.ietf.org/doc/html/draft-ietf-oauth-security-topics
 */
class SocialiteRegistrar
{
    /**
     * Bootstrap Socialite-related event listeners.
     *
     * Called from {@see \App\Providers\AppServiceProvider::boot()}.
     */
    public static function boot(): void
    {
        self::registerEventListener();
    }

    /**
     * Subscribe the SocialiteEventListener to the SocialiteWasCalled event.
     *
     * The socialiteproviders/microsoft package fires this event the first
     * time `Socialite::driver('microsoft')` is resolved; the listener then
     * extends the manager with the Microsoft provider class.
     *
     * Idempotent — Laravel's event dispatcher de-duplicates identical
     * listener registrations.
     */
    public static function registerEventListener(): void
    {
        Event::subscribe(\App\Listeners\SocialiteEventListener::class);
    }

    /**
     * Returns a Socialite driver instance configured for PKCE on Google.
     *
     * The OAuthController uses this helper instead of `Socialite::driver()`
     * directly when handling Google flows. Microsoft PKCE is automatic
     * (handled by the SocialiteProviders package), so this helper only
     * branches on driver name for Google.
     *
     * @param  string  $provider     'google' or 'microsoft'
     * @param  bool    $stateless    True for the mobile flow (no session)
     * @param  string|null  $pkceCodeVerifier  Optional PKCE verifier string —
     *                                          if provided, applied to Google
     *                                          via setPkceCode() before redirect.
     */
    public static function driver(
        string $provider,
        bool $stateless = false,
        ?string $pkceCodeVerifier = null,
    ): \Laravel\Socialite\Contracts\Provider {
        $driver = Socialite::driver($provider);

        if ($stateless) {
            $driver = $driver->stateless();
        }

        // PKCE attachment for Google. The Microsoft provider handles PKCE
        // internally and ignores any caller-supplied verifier.
        if ($provider === 'google' && $pkceCodeVerifier !== null && method_exists($driver, 'setPkceCode')) {
            $driver->setPkceCode($pkceCodeVerifier);
        }

        return $driver;
    }
}
