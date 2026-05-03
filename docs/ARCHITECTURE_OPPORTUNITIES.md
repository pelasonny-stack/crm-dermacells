# CRM Dermacells — Architecture Opportunities

**Reviewer:** architect-reviewer (strategic pass)
**Date:** 2026-05-02
**Scope reviewed:** `apps/api` (Laravel 12, ~368 PHP files, 102 test files, 74 migrations, 47 Eloquent models, 13 Phase folders, 13 V1 controllers, 26 Filament resources), `apps/web` (React PWA, 9 pages), `apps/mobile` (Expo, Expo Router), `packages/api-client` (axios factory only), `infra/terraform`, `docs/PLAN.md`, `docs/SECURITY_AUDIT.md`.
**Lens:** "MVP for one customer (Dermacells AR)" → "global multi-tenant SaaS for SMB pharma".
**Confidence:** Medium-High on PHP/Postgres findings (read code directly), Medium on cost projections (assumed AWS sa-east-1 list pricing), Low on uptake/OSS strategy (depends on go-to-market). Gaps documented at the end.

---

## 1. Top 10 architectural debts (cost of ignoring × refactor effort)

Ranked by `cost × effort` — high score = address first.

| # | Debt | Cost if ignored | Effort | Priority |
|---|---|---|---|---|
| 1 | **No `tenant_id` anywhere; RLS keyed only on `app.user_id` + `app.user_role`** (`SetPostgresRlsContext.php:80-85`, all 47 models, all 16 RLS migrations). Re-adding tenancy after launch = backfill every row + rewrite every policy + every UNIQUE index (CUIT, email) + audit log re-anchor. | Catastrophic (the entire schema is single-tenant by construction; second customer = second deploy + second DB) | High (4-6 weeks: add `tenant_id UUID NOT NULL DEFAULT current_setting('app.tenant_id')::uuid` to every table, GUC layer, composite UNIQUEs `(tenant_id, cuit)`, audit chain per tenant) | **P0** |
| 2 | **Audit log is one global HMAC chain** (`AuditObserver.php:149` advisory lock `audit_log_chain`). At >50 writes/sec the single advisory lock serialises every audited write across the app; at multi-tenant it serialises across customers. | High (write throughput ceiling ~50-200 writes/s; cross-tenant correlation impossible) | Medium (split lock key by `tenant_id`, change verifier to walk N chains) | **P0** |
| 3 | **Materialized views refreshed unconditionally** (`mv_director_pulse_today`, `mv_portfolio_health_monthly`, `mv_zone_health` — every 5min/hourly per `routes/console.php`). At 1k tenants × 3 MVs every 5min = 12k refreshes/hour → MV scan cost dominates the cluster. | Medium (cost), High (latency) | Medium (event-driven refresh via Postgres `LISTEN/NOTIFY` on `payments`/`sales` insert; or scheduled but bucketed by tenant tier) | **P1** |
| 4 | **`Customer::all()->first(...)` in WhatsApp ingest** (referenced in SECURITY_AUDIT MED-06: `IngestWhatsappWebhookJob.php:118-133`). Full table scan per webhook. At 10k customers × 100 inbound msg/day per tenant = 1M rows scanned daily *per tenant*. | High at scale | Low (1 day: functional index + `whereRaw`) | **P1** |
| 5 | **Three independent frontends with hand-typed API types** (`apps/web/src/api`, `apps/mobile/src/api`, `packages/api-client/src/types.ts` — but the package is just an axios factory, not generated). Drift guaranteed; PLAN.md promised `openapi-typescript` + `openapi-fetch` and Scribe — neither wired into `packages/api-client`. | Medium-High (every API change = 3 PRs) | Medium (1 sprint: wire Scribe → `openapi.yaml` → `openapi-typescript` → publish to package) | **P1** |
| 6 | **`app/Domain` is incomplete and inconsistent** — Sales/Stock/Billing/Auth have NO `Domain/` folder; their logic lives in `app/Actions/*` + `app/Models/*` + `app/Services/*`. AI/Audit/Commissions/Dashboards/DistributorFinance/Evolution/Payments/Customers DO have Domain folders. The bounded contexts are half-baked. | Medium (slows refactor toward services / multi-team work) | Medium (1 sprint: move Sales/Stock/Billing under `Domain/`, adopt one canonical pattern: Action + Service + Repository + Data DTO) | **P1** |
| 7 | **74 migrations, 8 of them "patch" RLS policies** (`*_patch_*_rls_*.php`, `*_revert_*.php`, `*_drop_*.php`, `*_fix_*_rls_bypass.php` between `2026_05_17` and `2026_05_19`). Indicates RLS policies were authored interactively and patched against bugs found in tests. New tables in the future will repeat this pattern. | Medium (every new domain = days of RLS firefighting) | Medium (1 sprint: extract a `Policy` DSL — PHP class per table generating `CREATE POLICY` SQL, with a single test pattern that runs the cross-role denial matrix) | **P2** |
| 8 | **Filament tightly coupled to the Eloquent models** (26 resources directly bind to models). When (a) read replicas come, (b) CQRS is needed for heavy reports, (c) multi-tenant: every Filament resource has to be re-pointed. Filament also runs in the same PHP process as the API → contention for FPM workers. | Medium (panel slowness becomes API slowness) | High (decompose Filament panel into separate ECS service with its own connection pool + `report_role`) | **P2** |
| 9 | **Sanctum SPA cookie + PAT in same Laravel process, no tenant binding on token** (config/sanctum.php). PATs have abilities but no `tenant_id` claim. Multi-tenant = token belonging to user A in tenant X must not be replayable against tenant Y. | High at multi-tenant | Low-Medium (extend `personal_access_tokens` with `tenant_id`; middleware enforces match against subdomain/header) | **P2** |
| 10 | **Test suite 472 tests, ~15s.** Suite is `RefreshDatabase` — every test re-runs 74 migrations against Postgres. Suite will grow super-linearly with phases 18+. | Medium (dev velocity) | Low (1-2 days: `php artisan migrate:fresh` once → `pg_dump` template DB → `CREATE DATABASE … TEMPLATE`; or `--testdox` parallel via `pestphp/pest-plugin-parallel`) | **P2** |

> Stripe-style "API design" debts (versioning, idempotency, problem+json) are mostly *handled* (RFC 7807 in PLAN.md, `IdempotencyKey` middleware exists). The debt is in **schema and tenancy**, not API surface.

---

## 2. Three critical-path investments for multi-tenant SaaS

### Investment A — Tenancy at the schema + GUC layer (4-6 weeks, P0)

**Recommendation:** **Row-Level tenancy with `tenant_id` everywhere + Postgres RLS** (single shared DB, single Laravel app).

Tradeoff matrix (recommended bolded):

| Model | Pros | Cons | Verdict |
|---|---|---|---|
| **Row-level `tenant_id` + RLS** | Cheapest infra; single migration; reuses today's RLS infra (`SET LOCAL app.tenant_id`) | Noisy neighbour at very high tenant load; per-tenant restore is harder | **Adopt for tenants 1-1000** |
| Schema-per-tenant | Hard isolation; `pg_dump --schema=tenant_X` for restore | Migration runner must loop N schemas; Laravel `migrate` not designed for this; connection pool fragmented | Adopt for tenants >1000 OR enterprise tier |
| DB-per-tenant | Maximum isolation; per-tenant compliance | 1k tenants = 1k RDS instances = $$$$; cross-tenant analytics impossible without ETL | Reserve for enterprise contracts only |

**Concrete migration (sketch):**
```sql
-- Phase A.1: add tenant_id NULL, default to "dermacells" sentinel UUID
ALTER TABLE customers ADD COLUMN tenant_id UUID;
UPDATE customers SET tenant_id = '00000000-0000-0000-0000-000000000001';
ALTER TABLE customers ALTER COLUMN tenant_id SET NOT NULL;

-- Phase A.2: make UNIQUE constraints composite
ALTER TABLE customers DROP CONSTRAINT customers_cuit_unique;
ALTER TABLE customers ADD CONSTRAINT customers_tenant_cuit_unique UNIQUE (tenant_id, cuit);

-- Phase A.3: RLS policy update
DROP POLICY customers_seller_own ON customers;
CREATE POLICY customers_tenant_isolated ON customers
  AS RESTRICTIVE FOR ALL TO app_role
  USING (tenant_id = NULLIF(current_setting('app.tenant_id', TRUE), '')::UUID);
```

Plus `SetPostgresRlsContext` adds:
```php
DB::statement(sprintf("SET LOCAL app.tenant_id = '%s'", $tenantId));
```
…resolved from subdomain (`acme.crm.app`) or `X-Tenant` header at the auth layer.

**Sanctum tokens** need a `tenant_id` claim; add a `tenant_id` column to `personal_access_tokens` and a `EnsureTokenMatchesTenant` middleware.

### Investment B — Billing + entitlements infrastructure (3-4 weeks, P0)

Today: zero billing code. To monetise: **Stripe (recommended over Paddle for B2B SaaS in LATAM via Stripe Argentina or USD billing through a US entity)**.

Minimum viable billing layer:

| Component | Tech | File path proposal |
|---|---|---|
| Plan catalogue | DB table `plans` + Stripe Product/Price IDs | `database/migrations/*_create_plans_table.php` |
| Subscription state | `tenant_subscriptions` (status, current_period_end, plan_id) | `app/Domain/Billing/Models/TenantSubscription.php` |
| Webhook handler | `POST /webhooks/stripe` (HMAC verify, idempotent) | `app/Http/Controllers/Webhooks/StripeWebhookController.php` |
| Entitlement service | `EntitlementGate::can($tenant, 'feature.ai')` | `app/Domain/Billing/Services/EntitlementGate.php` |
| AI usage attribution | extend `ai_usage` with `tenant_id` + nightly job posts to Stripe Meter API | `app/Jobs/Billing/ReportAiUsageJob.php` |
| Customer Portal | Stripe Customer Portal (saves you 6 months of building dunning/invoice/receipt UIs) | n/a — redirect URL |

**Pricing model recommendation:** seat-based ($X/user/month) + AI metering ($Y per 1M tokens). Don't try usage-based on customers/sales — too volatile in pharma sales cycles.

### Investment C — Observability + per-tenant cost attribution (2 weeks, P1)

Today: Sentry + CloudWatch (per PLAN.md). Multi-tenant SaaS needs:

- **Per-tenant cost dashboard** — tag every CloudWatch metric and AWS resource with `tenant_id`. Use AWS Cost Allocation Tags to roll up infra cost per tenant.
- **Per-tenant SLO** — request latency p95 keyed on `tenant_id` (Prometheus label). Today the only label is `route`; one noisy tenant degrades the SLO for everyone undetectably.
- **Tenant heartbeat** — last successful login, last sale created, AI tokens spent (already in `ai_usage`, just needs aggregation). Powers churn prediction + sales motions.

---

## 3. Five refactor proposals (with before/after)

### Refactor 1 — RLS Policy DSL (kills "patch_rls" migration churn)

**Before** — `database/migrations/2026_05_19_000003_revert_customer_policies_to_subquery.php` (≈200 lines of raw SQL per patch, repeated 8 times in 3 days).

**After** — declarative policy classes:

```php
// app/Domain/RowSecurity/Policies/CustomerPolicy.php
final class CustomerPolicy extends TenantScopedPolicy
{
    public string $table = 'customers';

    public function director(): Rule { return Rule::all(); }
    public function distributor(): Rule
    {
        return Rule::where("zone_id IN (SELECT id FROM zones WHERE distributor_id = :uid)");
    }
    public function seller(): Rule
    {
        return Rule::where("assigned_seller_id = :uid");
    }
}
```

A single command `php artisan rls:apply` regenerates `CREATE POLICY` SQL for every table. Test pattern shrinks from "1 test per (role × table × action)" to "1 generic asserter that walks the policy registry."

### Refactor 2 — Replace `routes/api.php` (415 lines) with route registries per Domain

**Before:** monolithic `routes/api.php`, every controller imported at top, every middleware group repeated. Adding a phase = appending to file.

**After:**
```php
// bootstrap/app.php
->withRouting(api: function () {
    foreach (glob(app_path('Domain/*/Http/routes.php')) as $f) require $f;
})
```
Each Domain owns its routes:
```php
// app/Domain/Sales/Http/routes.php
Route::middleware(['auth:sanctum', 'idle', 'tenant'])
    ->prefix('v1/sales')
    ->group(function () { Route::apiResource('/', SaleController::class); });
```
Symptom this fixes: today touching `routes/api.php` is a merge-conflict magnet.

### Refactor 3 — Generated typed API client (kills 3-codebase drift)

**Before:** `packages/api-client/src/client.ts` is a hand-written axios factory with `types.ts` typed by hand. PWA + Mobile each maintain `src/api/*.ts` callsite-typed.

**After:**
```jsonc
// packages/api-client/package.json
"scripts": {
  "generate": "openapi-typescript ../../apps/api/openapi.yaml -o src/schema.ts && tsc"
}
```
```ts
import createClient from 'openapi-fetch';
import type { paths } from './schema';
export const api = createClient<paths>({ baseUrl: '/api/v1' });
// usage: const { data, error } = await api.GET('/customers/{id}', { params: { path: { id }}});
```
- Scribe regenerates `openapi.yaml` on every PR (already promised in PLAN.md §1.10).
- Failing CI when generated client has uncommitted diff = forced sync.
- PWA + Mobile import the same types.

### Refactor 4 — Move heavy reads off the write path (CQRS-lite, no event sourcing)

**Before:** Filament resources + dashboard endpoints both run on the same Postgres role (`app_role`) under RLS. Director dashboards (`mv_director_pulse_today`) refresh every 5min on the same connection pool that serves OLTP.

**After:**

```php
// app/Domain/Reporting/Read/DashboardConnection.php
final class DashboardConnection {
    public static function for(User $user): \Illuminate\Database\ConnectionInterface {
        return DB::connection('pgsql_read'); // routed to RDS read replica, report_role
    }
}
```

- Add `pgsql_read` connection pointing at RDS read replica + `report_role` (already exists per PLAN.md).
- All `App\Domain\Dashboards\Queries\*` use this connection.
- Filament panel switches to `report_role` for indices and `app_role` only for mutations.
- Materialized view refresh moves to a dedicated worker on the *replica* (Postgres 16 supports refresh on a logical replica via `pg_dump`/`pg_restore`-style snapshot; or run on primary at off-hours and let replica catch up).

This is the cheapest CQRS that buys 80% of the perf. Full event sourcing of audit log (replayable state) is **not** recommended yet — the HMAC-chained audit log is *already* an event log; just add a projection step.

### Refactor 5 — Server-driven UI **for forms only**, keep React owning navigation

You proposed "server-driven UI to reduce 3 codebases to 1" — full SDUI is a 2-year rewrite. The pragmatic 80/20:

**Before:** `apps/web/src/pages/CustomerDetailPage.tsx` and `apps/mobile/app/(app)/customers/[id].tsx` both hand-render the same fields, both re-validate with their own logic.

**After:** `GET /api/v1/customers/{id}/form-schema` returns:
```json
{
  "fields": [
    {"name": "first_name", "type": "text", "label": "Nombre", "required": true, "max": 80},
    {"name": "cuit", "type": "text", "label": "CUIT", "validate": "ar_cuit"},
    {"name": "category_id", "type": "select", "options": [...], "permission": "director"}
  ]
}
```

PWA + Mobile both render this schema via a tiny `<DynamicForm schema={...}/>` component. Server-side: ditch duplicate `*Request` validators by building them from the same schema source (single Form Request + schema generator). Wins:

- Adding a field = 1 PHP edit, both clients pick up automatically.
- Permissions baked into the schema (`meta.permissions` already promised in PLAN.md §3) — invisible fields stay invisible.
- Page navigation, tables, dashboards stay native (where SDUI would hurt UX).

**Don't** apply this to lists or dashboards — too dynamic, too perf-sensitive. Forms are 70% of the UI work, and forms are where SDUI shines (cf. Shopify Polaris remote forms, Airbnb's DLS).

---

## 4. Open-source strategy

**Verdict:** Yes, there's a credible "Veeva for SMB pharma" open-core play, but **not the whole CRM** — the whole CRM is over-fit to Argentina (Xubio, BCRA, AFIP CAE, CUIT). Open-source the **horizontal infrastructure** that bigger projects can adopt, monetise the **vertical pharma logic** + hosted + AI.

| Layer | OSS or commercial? | Rationale |
|---|---|---|
| `app/Domain/Audit` (HMAC-chained, partition-month, REVOKE-protected, Observer auto-wire) | **OSS** as `laravel-immutable-audit` | No equivalent on Packagist today; Stripe/Plaid-style audit appeals to every fintech-adjacent shop |
| `app/Http/Middleware/SetPostgresRlsContext` + GUC pattern + Pgbouncer-transaction-mode docs | **OSS** as `laravel-postgres-rls` | spatie/laravel-multitenancy doesn't do RLS; this is genuinely novel + battle-tested |
| `app/Http/Middleware/IdempotencyKey` (Stripe-pattern) | **OSS** as `laravel-stripe-idempotency` | spatie has none; current PHP options are immature |
| Money DSL (`MoneyCast` over `NUMERIC + CHAR(3)` instead of BIGINT minor units) | **OSS** as `brick-money-eloquent-cast` | Brick doesn't ship a Laravel cast; community wants this |
| RFC 7807 problem+json + `meta.permissions` + Scribe macros | **OSS** as `laravel-problem-json` | Stripe/GitHub use this style; Laravel has no canonical impl |
| `Domain/Sales`, `Domain/Stock`, `Domain/DistributorFinance`, `Domain/Commissions` | **Commercial** (Dermacells-like vertical) | This is the moat |
| `Domain/AI` + LeakGuard + per-tenant token cap + provider-agnostic LLMClient | **OSS core** + commercial hosted | Anti-leak guard is novel; release as `laravel-llm-tenant-guard` |
| Xubio/BCRA/Meta clients | OSS as separate small packages (`xubio-php`, `bcra-cotizaciones-php`) | LATAM dev community starved of these; gives organic SEO + lead generation |

**Monetisation:** hosted multi-tenant CRM (subscription) + premium AI seats (token metering) + integrations marketplace (revenue share with Xubio-equivalents in MX/CL/CO). Open core is the *funnel* into hosted. GitHub stars on `laravel-postgres-rls` is what gets your sales team meetings.

---

## 5. Cost-to-serve projections (AWS sa-east-1, 2026 list pricing)

> Assumptions: 1 tenant = 50 active users avg, 10k customers, 5k sales/month, 200 AI queries/user/month, 100 inbound WhatsApp/day, Reverb websocket per active user during business hours (8h × 22 days).

### 100 tenants

| Resource | Sizing | Monthly USD |
|---|---|---|
| RDS Postgres `db.r6g.xlarge` Multi-AZ + 200GB gp3 | shared | 720 |
| ECS Fargate (API): 4 tasks × 1vCPU × 2GB | shared | 220 |
| ECS Fargate (Horizon worker): 2 tasks × 1vCPU × 2GB | shared | 110 |
| ECS Fargate (Reverb): 2 tasks × 0.5vCPU × 1GB | shared | 60 |
| ElastiCache Redis `cache.t4g.medium` | shared | 70 |
| S3 (invoices PDFs, audit dumps): 200GB + egress | per tenant grows | 30 |
| CloudWatch + Sentry teams plan | flat | 200 |
| AI tokens (200 q/user × 50 u × 100 t × 1500 input/500 output, GPT-4o-mini at $0.15/$0.60 per M) | 100 tenants | 600 |
| WhatsApp Cloud API (service window inbound) | $0 | 0 |
| **Total** | | **~$2,010 / mo = $20.10 per tenant** |

Pricing power: charge $99/mo seat-based × 50 seats = $4,950/tenant → 99% gross margin floor; reality with discounts ~$50/seat → $2,500 × 100 = $250k MRR with $200k margin.

### 1,000 tenants

| Resource | Sizing | Monthly USD |
|---|---|---|
| RDS Aurora Postgres `db.r6g.4xlarge` writer + 2 readers | shared | 4,800 |
| ECS Fargate (API): 30 tasks autoscaled | shared | 1,650 |
| ECS Fargate (Horizon): 8 tasks | shared | 440 |
| ECS Fargate (Reverb) — **switch to Pusher Channels at this scale: 50k concurrent** | managed | 800 |
| ElastiCache Redis cluster mode 3 nodes | shared | 320 |
| S3 (≈2TB) + CloudFront egress | per tenant | 350 |
| CloudWatch + Datadog (now needed, Sentry alone too coarse) | flat | 1,500 |
| AI tokens (10x the 100-tenant load) | 1k tenants | 6,000 |
| WhatsApp templates (1% of conversations break service window) | per tenant | 800 |
| **Total** | | **~$16,660 / mo = $16.66 per tenant** |

> Cost-per-tenant *drops* (better infra utilisation) — typical SaaS economics. AI is the fastest-growing line; hardcode caps and bill the overage.

### 10,000 tenants

At 10k tenants the architecture must change:

- **Sharded Postgres** by `tenant_id` (Citus extension on Aurora is not available on AWS — switch to self-managed Patroni + Citus on EC2 OR adopt **PlanetScale-style sharding via app layer**, OR move to **Aurora Limitless** when GA).
- **Cell-based architecture**: tenants partitioned into "cells" of ~500 tenants each; cell = 1 RDS + 1 ECS service. Failure domain isolated. Recommended pattern from AWS Well-Architected SaaS lens.
- Reverb is impossible at this scale → Pusher Channels or Ably (managed).
- Materialized view strategy must change to incremental MV refresh (pg_ivm extension) or Redshift/ClickHouse for analytics.

| Resource | Monthly USD |
|---|---|
| 20 cells × Aurora cluster | 60,000 |
| ECS Fargate fleet (~600 tasks across cells) | 33,000 |
| Pusher Channels Enterprise | 8,000 |
| Redis cluster per cell | 4,000 |
| S3 + CloudFront (~30TB egress) | 4,500 |
| Observability (Datadog Pro × volume) | 12,000 |
| AI tokens (raw cost — pass-through with margin) | 60,000 |
| WhatsApp templates | 8,000 |
| **Total** | **~$189,500 / mo = $18.95 per tenant** |

> The marginal cost per tenant stays in the **$15-20 range across all 3 scales**. Pricing >$200/tenant sustains. **AI is half the cost** at scale and is the variable to hedge (cache aggressively, use Anthropic prompt cache, force short responses, route to smaller models for routine tasks — the Domain/AI layer already supports provider switching at runtime per `ai_settings.model`, so this is already a lever).

---

## Confidence note + gaps

**High confidence:**
- File counts (102 tests, 74 migrations, 47 models, 26 Filament resources) — verified by `find | wc -l`.
- RLS-only-on-user-id (no tenant_id) — verified by reading 3 RLS migrations + Customer model + middleware.
- Audit log single-chain serialisation — verified by `AuditObserver::writeChainedRow()` advisory lock string `'audit_log_chain'` (no tenant scoping).
- 8 patch_rls migrations between May 17-19 — verified by `ls`.
- Three-codebase API typing — verified by reading `packages/api-client/src/client.ts` (axios factory only, no generation).
- Domain folder asymmetry (Sales/Stock/Billing missing) — verified by `find Domain -type d`.

**Medium confidence:**
- Test execution time (15s for 472 tests) — taken from your prompt, not measured. If tests use `RefreshDatabase` against Postgres they will scale super-linearly; if Sqlite then RLS isn't fully exercised.
- Reverb scaling ceiling — Laravel publishes ~1,000 concurrent connections per Reverb process; our recommendation to switch at 1k tenants assumes ~5% concurrent users.
- Materialized view refresh cost — depends on dataset size; at 10k tenants × 50 customers each MV is ~500k rows which is fine, but joins to `purchase_evolution_metrics` could explode.

**Low confidence (gaps to resolve before acting):**
- AI token cost projection assumes GPT-4o-mini class pricing and ~2k tokens/query; real usage will skew with prompt cache hit rate (Phase 13 promises caching but `ai_usage` rows weren't sampled).
- Open-source uptake is a marketing question; no signal in the repo.
- Per-tenant cost depends on actual usage curve; modelled at "1 Dermacells = 1 tenant" = optimistic for 50 users.
- LATAM Stripe pricing has nuance (Stripe Argentina launched 2024, USD billing requires US entity); confirm with finance.

**Notable absences from the repo (worth flagging):**
- No Octane / FrankenPHP — Laravel boots per request; with 30+ Fargate tasks at 1k tenants, the cold-boot tax is significant (~80ms per request). Octane = 4-10x throughput improvement. PLAN.md doesn't mention it.
- No `infra/terraform/*` modules listed beyond directory existence; couldn't verify the AWS topology assumptions.
- No load test results (`loadtest/` directory exists, contents not inspected). PLAN.md Phase 17 promises k6.
- No GraphQL — and that's correct. **Don't add GraphQL.** REST + generated typed client + `meta.permissions` solves over-fetching at lower complexity.
- No outbound webhooks for customers — recommended for the SaaS phase (so Dermacells-the-customer can subscribe to `sale.delivered` and post into their own ERPs). Add `tenant_webhooks` table, signed `X-Webhook-Signature: hmac-sha256=…` (Stripe pattern).

---

## Files referenced

- `/Users/gcardarelli/projects/crm-dermacells/docs/PLAN.md`
- `/Users/gcardarelli/projects/crm-dermacells/docs/SECURITY_AUDIT.md`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/app/Http/Middleware/SetPostgresRlsContext.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/app/Domain/Audit/Observers/AuditObserver.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/app/Models/Customer.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/database/migrations/2026_05_02_000006_enable_rls_policies.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/database/migrations/2026_05_14_000001_create_dashboard_materialized_views.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/database/migrations/2026_05_19_000003_revert_customer_policies_to_subquery.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/routes/api.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/composer.json`
- `/Users/gcardarelli/projects/crm-dermacells/packages/api-client/src/client.ts`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/app/Domain/AI/Services/CustomerContextBuilder.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/app/Domain/Commissions/Seller/Services/CommissionCalculatorService.php`
- `/Users/gcardarelli/projects/crm-dermacells/apps/api/app/Actions/Sales/ConfirmSaleAction.php`
