# CRM Dermacells — Expo Mobile App

iOS + Android app for the CRM Dermacells platform. Built with Expo SDK 54, Expo Router v4, and TypeScript strict mode.

## Requirements

- Node 20+
- pnpm (monorepo package manager)
- Xcode (iOS builds — macOS only)
- Android Studio + emulator (Android builds)
- EAS CLI: `npm install -g eas-cli` (for cloud builds)

## Dev setup

```bash
# From monorepo root
pnpm install

# Copy and fill env vars
cp apps/mobile/.env.example apps/mobile/.env

# Start Metro bundler
cd apps/mobile
npx expo start --tunnel

# iOS (requires Xcode)
# Press i in the Metro terminal

# Android (requires Android Studio emulator running)
# Press a in the Metro terminal

# Or scan the QR code in the Metro terminal with Expo Go on a physical device
```

## Environment variables

See `.env.example`. Key variables:

| Variable | Description |
|---|---|
| `EXPO_PUBLIC_API_BASE_URL` | Backend API URL, e.g. `http://localhost:8000/api/v1` |
| `EXPO_PUBLIC_GOOGLE_CLIENT_ID` | Google OAuth client ID |
| `EXPO_PUBLIC_MICROSOFT_CLIENT_ID` | Microsoft/Azure AD client ID |
| `EXPO_PUBLIC_MICROSOFT_TENANT_ID` | Azure AD tenant (default: `common`) |
| `EXPO_PUBLIC_EAS_PROJECT_ID` | EAS project ID from expo.dev |

OAuth callbacks use the custom scheme `dermacells://oauth/callback`. Register this redirect URI in Google Cloud Console and Azure AD.

## Auth flow

1. User taps "Continuar con Google" or "Continuar con Microsoft".
2. `expo-auth-session` opens the provider via ASWebAuthenticationSession (iOS) / Custom Tabs (Android) using PKCE.
3. Backend `POST /api/v1/auth/oauth/{provider}` exchanges the code for a Sanctum PAT.
4. App prompts biometric (Face ID / fingerprint) via `expo-local-authentication`.
5. On biometric success, token is saved to `expo-secure-store` (WHEN_UNLOCKED_THIS_DEVICE_ONLY).
6. Subsequent launches: token retrieved from SecureStore, gated behind biometric.

## Push notifications

Device tokens are registered via `POST /api/v1/devices` (to be implemented in Phase 14). FCM project requires `google-services.json` in `apps/mobile/` (Android) and APNs key configured in EAS.

## EAS Build

```bash
# Development build (installs dev client on device)
eas build --profile development --platform all

# Preview APK/IPA (internal testing)
eas build --profile preview --platform all

# Production (App Store / Play Store)
eas build --profile production --platform all
```

## OTA updates

```bash
# Publish an update to the production channel
eas update --branch production --message "Fix: ..."
```

## App Store / Play Store submission

```bash
eas submit --platform ios --profile production
eas submit --platform android --profile production
```

## Project structure

```
apps/mobile/
  app/
    _layout.tsx           # Root layout — QueryClient + AuthGate
    (auth)/
      _layout.tsx         # Stack for unauthenticated screens
      login.tsx           # OAuth login screen
      biometric-prompt.tsx # Biometric activation after first OAuth
    (app)/
      _layout.tsx         # Tab navigator (Tablero, Clientes, Ventas, Cobros, Alertas)
      index.tsx           # Tablero (role-aware dashboard)
      customers/
        index.tsx         # Customer list (FlashList + search)
        [id].tsx          # Customer detail with tabs
      sales/
        index.tsx         # Sale list (FlashList)
        new.tsx           # New draft sale form
      payments/
        index.tsx         # Payment list (FlashList)
      alerts/
        index.tsx         # Alerts list (FlashList)
  src/
    api/
      client.ts           # Axios singleton with auth interceptors
      types.ts            # Minimal TS types (Phase 15 scaffold)
    auth/
      auth-store.ts       # Zustand store (token + user + pendingToken)
      secure-storage.ts   # expo-secure-store wrapper with biometric gate
      oauth.ts            # Google + Microsoft PKCE OAuth via expo-auth-session
    notifications/
      register-device.ts  # FCM token registration
      notification-handler.ts # Foreground notification display config
```
