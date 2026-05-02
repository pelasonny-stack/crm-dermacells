/**
 * Sentry initialisation for the CRM Dermacells Expo (React Native) app.
 *
 * Import this file at the top of App.tsx before rendering any component.
 *
 * Package required: @sentry/react-native
 * Install: pnpm add @sentry/react-native --filter @dermacells/mobile
 *
 * After install, run: npx @sentry/wizard@latest -i reactNative
 * (patches metro.config.js and babel.config.js for source maps)
 */

import * as Sentry from '@sentry/react-native';

const isProd = process.env.APP_ENV === 'production';
const dsn    = process.env.EXPO_PUBLIC_SENTRY_DSN;

if (dsn) {
  Sentry.init({
    dsn,

    environment: process.env.APP_ENV ?? 'development',
    release:     process.env.EXPO_PUBLIC_SENTRY_RELEASE,

    // --- Sample rates ---
    tracesSampleRate: isProd
      ? parseFloat(process.env.EXPO_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? '0.05')
      : 1.0,

    // Enable Native crash reporting (iOS + Android native crashes)
    enableNative: true,

    // Attach stack traces to all messages (not just exceptions)
    attachStacktrace: true,

    // --- Integrations ---
    integrations: [
      Sentry.mobileReplayIntegration({
        // Mask all text + images in session replays (PII protection)
        maskAllText:   true,
        maskAllImages: true,
      }),
    ],

    // --- Ignore rules ---
    ignoreErrors: [
      // Sanctum 401 — normal re-login flow (biometric + OAuth)
      'Unauthenticated',
      // Network errors when device is offline (expected on mobile)
      'Network request failed',
      'NetworkError',
      'No network connection',
      // Expo SecureStore errors on non-enrolled devices (no biometrics set up)
      'SecureStore',
      // iOS WebView errors
      'The operation was cancelled',
      'cancelled',
    ],

    // --- Before send: strip PII ---
    beforeSend(event) {
      if (event.user) {
        delete event.user.email;
        delete event.user.username;
        delete event.user.ip_address;
      }

      // Drop 401/403 (auth boundary — expected, not a bug)
      const status = event.tags?.['http.status_code'];
      if (status === 401 || status === 403) {
        return null;
      }

      return event;
    },
  });
}

/**
 * Set Sentry user context after successful OAuth + Sanctum token exchange.
 *
 * Usage in auth callback:
 *   setSentryUser({ id: user.id, role: user.role });
 *
 * Call setSentryUser(null) on logout.
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
