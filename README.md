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

---

## Production deployment (Phase 17)

Production infrastructure is managed with Terraform targeting AWS sa-east-1.
All services run on ECS Fargate (Graviton ARM64). See the links below for full docs.

| Document | Description |
|---|---|
| [infra/terraform/README.md](infra/terraform/README.md) | Terraform bootstrap, deploy, and rollback |
| [docs/DR_RUNBOOK.md](docs/DR_RUNBOOK.md) | Disaster recovery — PITR restore, audit chain integrity, ECS rollback |
| [docs/2FA_ENFORCEMENT.md](docs/2FA_ENFORCEMENT.md) | Google Workspace + Microsoft Entra MFA enforcement for Directors |
| [loadtest/k6/README.md](loadtest/k6/README.md) | k6 load test usage (must pass before go-live) |

### Infrastructure overview

| Component | AWS service | Notes |
|---|---|---|
| API (FrankenPHP) | ECS Fargate | ARM64 Graviton, rolling deploy with circuit breaker |
| Horizon worker | ECS Fargate | Separate service, 4 priority queues |
| Reverb WebSockets | ECS Fargate | Path-routed via ALB (`/app/*`) |
| Scheduler | ECS Fargate | desiredCount=1 always (not distributed-safe) |
| Database | Aurora PostgreSQL 16 | Multi-AZ production, PITR 30d, force_ssl=on |
| Cache / Queues | ElastiCache Redis 7 | Cluster mode disabled (Horizon compatibility) |
| PWA static | S3 + CloudFront | OAC, SPA rewrite function, security headers |
| Secrets | Secrets Manager | `dermacells/production` — all app secrets |
| Audit exports | S3 Object Lock COMPLIANCE 7yr | Tamper-proof audit log dumps |
| Monitoring | CloudWatch (12 alarms) + Sentry | PagerDuty integration for critical alerts |

### Pre-go-live checklist

- [ ] `terraform apply` on production succeeds cleanly
- [ ] All 12 CloudWatch alarms are active
- [ ] k6 `dashboard-flow.js` passes: p95 < 800ms, error rate < 1%
- [ ] DR test: PITR restore to staging validated
- [ ] `php artisan audit:verify` passes on production data
- [ ] Sentry DSN configured for all 3 apps (backend, web, mobile)
- [ ] PagerDuty integration key in Secrets Manager
- [ ] 2FA enforced for all Director accounts (see `docs/2FA_ENFORCEMENT.md`)
- [ ] EAS production build submitted to App Store + Play Store
- [ ] Meta Business Verification confirmed active
