<?php

declare(strict_types=1);

namespace App\Services\Xubio;

use App\Services\Xubio\Exceptions\XubioUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Token manager for Xubio OAuth2 client_credentials flow.
 *
 * Xubio token TTL is 3600s. We refresh proactively at 50min using an
 * atomic Cache::lock so concurrent workers do not stampede the auth endpoint.
 *
 * The lock pattern (`Cache::lock(...)->block(5, ...)`):
 *   - Block up to 5s for an in-flight refresh to finish.
 *   - Hold the lock for 10s while we POST to /token.
 *   - Cache the new token for TTL_SECONDS so subsequent calls hit cache.
 */
final class TokenManager
{
    private const CACHE_KEY        = 'xubio:access_token';
    private const REFRESH_LOCK_KEY = 'xubio:token:refresh';
    private const TTL_SECONDS      = 3000; // 50 minutes (60min real TTL minus buffer)

    /**
     * Returns a valid Xubio access token, refreshing transparently if needed.
     *
     * @throws XubioUnavailableException
     */
    public function getToken(): string
    {
        // Fast path — cache hit
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Slow path — atomic refresh
        $lock = Cache::lock(self::REFRESH_LOCK_KEY, 10);

        try {
            return $lock->block(5, function (): string {
                // Re-check cache inside the lock — another worker may have just refreshed.
                $cached = Cache::get(self::CACHE_KEY);
                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }

                $token = $this->fetchNewToken();
                Cache::put(self::CACHE_KEY, $token, self::TTL_SECONDS);

                return $token;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw new XubioUnavailableException(
                'Could not acquire Xubio token refresh lock within 5s.',
                0,
                $e,
            );
        }
    }

    /**
     * Force-invalidate the cached token (e.g., on 401 response).
     */
    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Hit Xubio /token with client_credentials grant.
     *
     * @throws XubioUnavailableException
     */
    private function fetchNewToken(): string
    {
        $tokenUrl = (string) config('services.xubio.token_url');
        $clientId = (string) config('services.xubio.client_id');
        $secret   = (string) config('services.xubio.secret');

        if ($tokenUrl === '' || $clientId === '' || $secret === '') {
            throw new XubioUnavailableException(
                'Xubio OAuth credentials are not configured (XUBIO_TOKEN_URL / XUBIO_CLIENT_ID / XUBIO_CLIENT_SECRET).'
            );
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($tokenUrl, [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientId,
                    'client_secret' => $secret,
                ]);
        } catch (\Throwable $e) {
            throw new XubioUnavailableException(
                'Xubio /token request failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($response->failed()) {
            Log::error('Xubio token endpoint returned non-2xx', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new XubioUnavailableException(
                sprintf('Xubio /token returned HTTP %d', $response->status())
            );
        }

        $body  = $response->json();
        $token = is_array($body) ? ($body['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new XubioUnavailableException('Xubio /token response missing access_token');
        }

        return $token;
    }
}
