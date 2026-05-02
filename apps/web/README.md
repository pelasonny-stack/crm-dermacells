# CRM Dermacells — Web PWA

React 18 PWA for Vendedores and Distribuidores. Consumes `/api/v1/*` via Sanctum SPA cookie auth.

## Dev workflow

```bash
cd apps/web
pnpm install
pnpm dev    # http://localhost:5173
```

Backend must be running on `http://127.0.0.1:8000`:

```bash
cd apps/api
php artisan serve   # or: sail up
```

## Auth flow

1. User clicks "Continuar con Google" or "Continuar con Microsoft" on `/login`.
2. Browser redirects to `/auth/google/redirect` (proxied to Laravel by Vite).
3. Laravel Socialite handles OAuth + sets Sanctum session cookie.
4. Laravel redirects back to `http://localhost:5173/` (configure `OAUTH_REDIRECT_URL` in `apps/api/.env`).
5. App boot calls `GET /sanctum/csrf-cookie` then `GET /api/v1/auth/me` to hydrate auth store.

## Environment

All env vars live in `apps/api/.env`. The web app has no `.env` of its own — Vite proxies everything through the backend in dev. In production the SPA is served from a CDN and calls the API directly.

Required in `apps/api/.env`:

```
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
SESSION_DOMAIN=null
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
```

## Build

```bash
pnpm build    # outputs to dist/
pnpm preview  # preview production build locally
```
