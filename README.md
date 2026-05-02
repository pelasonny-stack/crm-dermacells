# CRM Dermacells

CRM/ERP comercial para Dermacells S.A. — Línea AGF Mesenchymal (LiveCells).

## Stack

- **Backend**: Laravel 12 (PHP 8.3+) headless API
- **Web**: React PWA (apps/web)
- **Mobile**: Expo (React Native + EAS) (apps/mobile)
- **DB**: PostgreSQL 16 + Redis
- **Hosting**: AWS sa-east-1

## Estructura monorepo

```
apps/
  api/          # Laravel 12 backend
  web/          # React PWA
  mobile/       # Expo iOS + Android
packages/
  api-client/   # TypeScript client compartido (openapi-typescript)
docs/
  PLAN.md       # Plan fasado completo
scripts/        # DevOps + bootstrap scripts
```

## Desarrollo local

Requisitos:
- PHP 8.3+ + Composer
- PostgreSQL 16+ con `pg_partman`
- Redis 7+
- Node 20+ + pnpm

Ver `apps/api/README.md` para setup detallado.

## Plan de implementación

Ver [docs/PLAN.md](./docs/PLAN.md) — 17 fases / ~9 meses full o ~5 meses MVP.

## Web PWA (apps/web)

React 18 + Vite + Tailwind v4 PWA. Sanctum SPA cookie auth via Google/Microsoft OAuth.
Roles: Vendedor, Distribuidor (Director uses Filament admin at `/admin`).

```bash
# Dev
cd apps/web
pnpm install
pnpm dev    # http://localhost:5173
# Backend must run on http://127.0.0.1:8000
```

Routes: `/login` (OAuth), `/` (dashboard), `/customers`, `/customers/:id`, `/sales`, `/sales/:id`, `/payments`, `/alerts`, `/me`.

See `apps/web/README.md` for full details.

## Mobile app (Phase 15)

Expo SDK 54 + Expo Router v4. Auth via Google / Microsoft OAuth PKCE + biometric (Face ID / huella) via `expo-local-authentication`. Token en `expo-secure-store`. Builds con EAS, OTA via EAS Update.

```bash
# Dev
cd apps/mobile
cp .env.example .env   # fill GOOGLE_CLIENT_ID, MICROSOFT_CLIENT_ID, API_BASE_URL
pnpm install
npx expo start --tunnel
# iOS: press i  |  Android: press a  |  QR: Expo Go app
```

Ver `apps/mobile/README.md` para detalles completos de setup, EAS Build y submission.
