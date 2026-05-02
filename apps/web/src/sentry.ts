/**
 * Sentry initialisation for the CRM Dermacells React PWA.
 *
 * Import this file at the very top of src/main.tsx (before React renders)
 * so that errors during hydration and router setup are captured.
 *
 * Package required: @sentry/react
 * Install: pnpm add @sentry/react --filter @dermacells/web
 */

import * as Sentry from '@sentry/react';

const isProd = import.meta.env.VITE_APP_ENV === 'production';
const dsn    = import.meta.env.VITE_SENTRY_DSN_WEB;

// Only initialise if DSN is configured.
// In local dev leave VITE_SENTRY_DSN_WEB blank to disable.
if (dsn) {
  Sentry.init({
    dsn,

    environment: import.meta.env.VITE_APP_ENV ?? 'local',
    release:     import.meta.env.VITE_SENTRY_RELEASE,

    // --- Sample rates ---
    // Production: 5% of sessions traced (performance visibility without quota burn)
    // Staging: override to 1.0 via VITE_SENTRY_TRACES_SAMPLE_RATE=1
    tracesSampleRate: isProd
      ? parseFloat(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? '0.05')
      : 1.0,

    // Replay: capture 5% of sessions, 100% of sessions with an error
    replaysSessionSampleRate: isProd ? 0.05 : 0.0,
    replaysOnErrorSampleRate: isProd ? 1.0 : 0.0,

    // --- Integrations ---
    integrations: [
      Sentry.browserTracingIntegration({
        // Instrument navigation (React Router v6 data API)
        // instrumentNavigation is true by default
      }),
      Sentry.replayIntegration({
        // Mask all text and inputs by default — PII protection
        maskAllText:   true,
        blockAllMedia: true,
      }),
    ],

    // --- Sanctum-aware ignore rules ---
    // Do not report network errors caused by expected auth flows.
    ignoreErrors: [
      // Sanctum CSRF mismatch (user opened stale tab)
      'CSRF token mismatch',
      'csrf_token',
      // Sanctum 401 on expired session (normal re-login flow)
      'Unauthenticated',
      // Network errors from the iOS WebView (common in PWA on iOS 16)
      'Network request failed',
      'NetworkError',
      // ResizeObserver benign browser error
      'ResizeObserver loop limit exceeded',
      'ResizeObserver loop completed',
      // Cancelled fetch (user navigated away)
      'AbortError',
      'The user aborted a request',
    ],

    // --- URL allowlist ---
    // Only trace requests to our own backend; avoid tracing CDN/S3 requests.
    tracePropagationTargets: [
      'localhost',
      /^https:\/\/(api\.|staging\.)?dermacells\.com\.ar/,
    ],

    // --- Before send: strip PII ---
    beforeSend(event) {
      // Remove user email from events (GDPR / Argentine data protection law)
      if (event.user) {
        delete event.user.email;
        delete event.user.username;
        delete event.user.ip_address;
      }

      // Skip 401/403 errors from expected auth enforcement
      const statusCode = event.tags?.['http.status_code'];
      if (statusCode === 401 || statusCode === 403) {
        return null;
      }

      return event;
    },
  });
}

/**
 * Returns the current Sentry scope for setting user context after login.
 *
 * Usage in auth callback:
 *   setSentryUser({ id: user.id, role: user.role });
 */
export function setSentryUser(user: { id: number; role: string } | null): void {
  if (!dsn) return;

  if (user) {
    Sentry.setUser({ id: String(user.id), role: user.role });
  } else {
    Sentry.setUser(null);
  }
}

export { Sentry };
