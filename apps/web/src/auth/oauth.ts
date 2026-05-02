/**
 * OAuth redirect helpers for Google and Microsoft via Laravel Socialite.
 *
 * Flow:
 *   Browser → /auth/google/redirect (Laravel) → IdP consent screen
 *   → /auth/google/callback (Laravel) → sets Sanctum session cookie
 *   → redirects back to SPA at /
 *
 * The Vite dev proxy forwards /auth/* to http://127.0.0.1:8000.
 * In production the domain resolves directly to the Laravel backend.
 */

/**
 * Redirect the browser to Google OAuth consent screen.
 * Laravel Socialite handles PKCE and hd (hosted domain) tenant restriction.
 */
export function loginWithGoogle(): void {
  window.location.href = '/auth/google/redirect';
}

/**
 * Redirect the browser to Microsoft OAuth consent screen.
 * Laravel Socialite handles tid (tenant ID) allowlist validation.
 */
export function loginWithMicrosoft(): void {
  window.location.href = '/auth/microsoft/redirect';
}

/**
 * POST logout — clears the Sanctum session server-side.
 * The auth store calls this so the axios instance is already configured.
 */
export async function serverLogout(): Promise<void> {
  await fetch('/auth/logout', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      // XSRF-TOKEN cookie is sent automatically by the browser as
      // X-XSRF-TOKEN via the axios interceptor on the apiClient instance,
      // but since we use fetch here we read it manually.
      'X-XSRF-TOKEN': getCsrfToken(),
    },
  });
}

function getCsrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  if (!match) return '';
  return decodeURIComponent(match[1]);
}
