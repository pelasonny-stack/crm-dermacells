# CRM Dermacells — Security Audit Report

**Auditor:** automated code review (Claude security-auditor)
**Date:** 2026-05-02
**Scope:** Phases 1-17, Laravel 12 API + React PWA + Expo, OWASP Top 10 (web), API Top 10, LLM Top 10
**Commit reviewed:** 5913461

## Executive Summary

| Severity | Count |
|---|---|
| Critical | 4 |
| High | 7 |
| Medium | 8 |
| Low / informational | 4 |
| **Total** | **23** |

The codebase shows a generally mature security posture: PostgreSQL RLS with `SET LOCAL` GUCs in a per-request transaction, an HMAC-chained immutable `audit_log`, Sanctum SPA + PAT split, OAuth-only login with `(provider, sub)` binding, idempotency middleware on POSTs, encrypted WhatsApp message bodies, and a LeakGuard post-filter on AI responses. The audit identifies 4 critical and 7 high findings to fix before production cutover.

**Key clusters:**
1. Sanctum PAT scope falling back to `['*']` when `UserRole::abilities()` returns empty
2. WhatsApp webhook `verify` non-constant-time + signature middleware passes GET unchecked + no replay protection
3. RLS GUC injection regex allows all-zero UUID + fail-open paths in middleware
4. `/dev-login` route gated only by `APP_ENV` (single env slip = total compromise)
5. Missing security HTTP headers (CSP/HSTS/XFO/XCTO) globally
6. CORS + SESSION_SECURE_COOKIE + APP_DEBUG defaults in `.env.example` are dev-friendly fail-open

## Findings

### CRIT-01 — Sanctum PAT abilities default to wildcard `['*']`
**Risk:** `OAuthController::resolveAbilities()` returns `['*']` whenever `UserRole::abilities()` returns empty. Stolen Expo SecureStore token = full director access.
**Location:** `app/Http/Controllers/Auth/OAuthController.php:329-335, 386-399`
**Recommendation:** Fail-closed default `['user:read']`. Boot-time assert that every role enum returns non-empty abilities.
**OWASP:** API1:2023, A01:2021.

### CRIT-02 — `/dev-login` only gated by `APP_ENV`
**Risk:** A single misconfigured `.env` (APP_ENV=local rolled to staging/prod) exposes RLS-bypassing director access. Bypasses `SetPostgresRlsContext` + `CheckIdleTimeout` middleware via `withoutMiddleware()`.
**Location:** `routes/web.php:50-67`
**Recommendation:** Wrap registration in `if (env('ALLOW_DEV_LOGIN') === 'yes' && app()->environment('local'))`. Add CI guard rejecting `ALLOW_DEV_LOGIN` in non-local artifacts. Move route to `routes/dev.php` only loaded under `php artisan serve --env=local`.
**OWASP:** A05:2021, A07:2021.

### CRIT-03 — WhatsApp `verify_token` compared with `===` + signature middleware passes GET unchecked
**Risk:** Non-constant-time string compare leaks token via timing. Verify endpoint has no signature, no IP allowlist, no rate limit.
**Location:** `app/Http/Controllers/Webhooks/WhatsappWebhookController.php:51`, `app/Http/Middleware/WhatsappWebhookSignature.php:37-39`
**Recommendation:** `hash_equals()` + IP allowlist for Meta source ranges + `throttle:10,1` per IP.
**OWASP:** API8:2023, A02:2021.

### CRIT-04 — WhatsApp webhook lacks replay-attack protection
**Risk:** No timestamp window check, no nonce store at signature layer. Captured signed POST replayable indefinitely. With leaked `WHATSAPP_APP_SECRET` attacker creates unbounded fake inbound messages.
**Location:** `app/Services/Meta/MetaClient.php:64-80`, `app/Http/Controllers/Webhooks/WhatsappWebhookController.php:71-78`
**Recommendation:** (1) `WHATSAPP_APP_SECRET` from AWS Secrets Manager. (2) Reject payloads with `messages[].timestamp` >10min old. (3) Redis SET of seen `wa_message_id` with 24h TTL — reject before dispatch.
**OWASP:** API2:2023, A07:2021.

---

### HIGH-01 — RLS context middleware fail-open paths + weak UUID regex
**Risk:** Returns `$next($request)` without opening transaction or setting GUCs in 3 branches. Routes accidentally outside `auth:sanctum` group execute under `app_role` with no GUCs. Regex accepts all-zero UUID.
**Location:** `app/Http/Middleware/SetPostgresRlsContext.php:38-86`
**Recommendation:** Tighten regex to `/^[0-9a-f]{8}-[0-9a-f]{4}-[47][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i`. Throw RuntimeException on `$role === null` post-probe. CI check that every Eloquent route is auth-guarded.
**OWASP:** API1:2023, A01:2021.

### HIGH-02 — Idempotency middleware: replay returns stored bodies for `Auth::id() === null`
**Risk:** If middleware registered without `auth:sanctum`, NULL user_id buckets all unauthenticated replays into single bucket. Path not in lookup composite key. No transaction wrapping `next()` — crashes leave IN_FLIGHT for 24h.
**Location:** `app/Http/Middleware/IdempotencyKey.php:60-119`
**Recommendation:** Hard-fail on null user_id. Include `request_path` in key. Try/finally to clear IN_FLIGHT. CI guard.
**OWASP:** API1:2023, API4:2023.

### HIGH-03 — AI prompt injection via WhatsApp message bodies
**Risk:** `CustomerContextBuilder::lastWhatsappMessages()` injects decrypted `body` verbatim into JSON context. Customer can craft message with injection prompt. LeakGuard only blocks UUIDs, not prose dumps of names/phones/balances.
**Location:** `app/Domain/AI/Services/CustomerContextBuilder.php:179-203`
**Recommendation:** Wrap WhatsApp bodies in delimited block `<<<UNTRUSTED_CUSTOMER_MESSAGE>>>...<<<END>>>`. Strip control sequences. Truncate to 500 chars per message. Extend LeakGuard to detect seller identifiers + email addresses.
**OWASP:** LLM01:2025, LLM06:2025.

### HIGH-04 — `AskNaturalLanguageQuestion` skips LeakGuard on streamed output
**Risk:** Stream cannot be retro-checked. Cross-customer contamination possible via tool output paraphrasing.
**Location:** `app/Domain/AI/UseCases/AskNaturalLanguageQuestion.php:42-84`
**Recommendation:** Buffer + LeakGuard against caller's allowed-customer-set, then yield. Or per-chunk regex scan with abort.
**OWASP:** LLM06:2025, LLM02:2025.

### HIGH-05 — No security HTTP headers (CSP/HSTS/XFO/XCTO)
**Risk:** Filament `/admin` served without CSP, HSTS, X-Frame-Options, X-Content-Type-Options. Reflected XSS in any Filament resource = stolen session cookie. OAuth callback iframable for clickjacking.
**Location:** `apps/api/bootstrap/app.php`
**Recommendation:** Add `bepsvpt/secure-headers` or custom middleware. Production headers:
```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; frame-ancestors 'none'
```
**OWASP:** A05:2021, A03:2021, A06:2021.

### HIGH-06 — `.env.example` defaults are dev-fail-open
**Risk:** `SESSION_SECURE_COOKIE=false`, `APP_DEBUG=true`, `SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:5173,localhost`, CORS defaults to localhost. If example file promoted to prod = plaintext cookies + debug stack traces leaking secrets + localhost as credentialed origin.
**Location:** `.env.example:1-54, 47, 95-101`, `config/cors.php:23`
**Recommendation:** Ship separate `.env.production.example` with production-safe defaults. Force operator to set stateful_domains + cors. Add `php artisan dermacells:assert-prod-config` boot-time gate.
**OWASP:** A05:2021, A02:2021.

### HIGH-07 — `AdminAccessGate` empty allowlist = "no restriction"
**Risk:** Production deploy forgetting `ADMIN_IP_ALLOWLIST` exposes Filament `/admin` to world. No CIDR support tempts operators to disable check entirely.
**Location:** `app/Http/Middleware/AdminAccessGate.php:101-116`
**Recommendation:** Fail-closed when allowlist empty AND `APP_ENV=production`. CIDR support via `IpUtils::checkIp4`. Demote `Log::info('AdminAccessGate hit')` to debug.
**OWASP:** A01:2021, A05:2021.

---

### MED-01 — Sanctum `currentApplicationUrlWithPort()` opens spoofing
**Risk:** Subdomain takeover later configured as reverse proxy origin = stateful session bypassing bearer.
**Location:** `apps/api/config/sanctum.php:18-23`
**Recommendation:** Pin `SANCTUM_STATEFUL_DOMAINS` per env. Boot-time validator no `*`.
**OWASP:** A07:2021.

### MED-02 — OAuth callback no rate limit
**Risk:** OAuth code replay window. Tenant kick-out failures undetected.
**Location:** `routes/api.php:62-65`, `routes/web.php:30-32`
**Recommendation:** `throttle:10,1` per IP. Sentry alert at 30 fails/5min.
**OWASP:** A07:2021, API4:2023.

### MED-03 — `?client=mobile` query param overrides flow detection
**Risk:** Phishing link returns 30-day PAT in JSON instead of session cookie.
**Location:** `app/Http/Controllers/Auth/OAuthController.php:215-220`
**Recommendation:** Drop query-param fallback. Require header.
**OWASP:** A07:2021.

### MED-04 — Audit HMAC key placeholder in `.env.example`
**Risk:** Deploy that copies placeholder `base64:CHANGE_ME_GENERATE_RANDOM_32_BYTES` produces working but trivially-forgeable HMAC chain (key public).
**Location:** `app/Domain/Audit/Observers/AuditObserver.php:204-228, 239-252`, `.env.example:106`
**Recommendation:** Boot-time assert key != placeholder. Move to AWS Secrets Manager. Add `audit:verify --check-key` subcommand.
**OWASP:** A02:2021, A05:2021.

### MED-05 — Xubio token cache key not env-namespaced
**Risk:** Shared Redis = cross-environment credential pollution. No TLS pinning.
**Location:** `app/Services/Xubio/TokenManager.php:25-27`, `app/Services/Xubio/XubioClient.php:337-345`
**Recommendation:** Prefix all Redis keys with `config('app.env')`. Verify CA bundle for corporate proxies.
**OWASP:** A02:2021, API8:2023.

### MED-06 — `IngestWhatsappWebhookJob::processMessage` full table scan
**Risk:** `Customer::all()->first(...)` loads every customer per webhook. Worker likely BYPASSRLS = whole-tenant scan.
**Location:** `app/Jobs/Whatsapp/IngestWhatsappWebhookJob.php:118-133`
**Recommendation:** Functional index `regexp_replace(phone, '\D', '', 'g')`. Switch lookup to indexed `whereRaw`. Verify worker_role explicitly NO BYPASSRLS.
**OWASP:** API3:2023, A04:2021.

### MED-07 — `InvoiceController::pdf` streams S3 via app + no per-user check on visibility
**Risk:** PDF URLs from Xubio verbatim — poisoning low likelihood but `Storage::get()` could read arbitrary S3 keys.
**Location:** `app/Http/Controllers/Api/V1/InvoiceController.php:147-169`
**Recommendation:** S3 pre-signed URLs (15min TTL) + 302 redirect. Validate URL regex `^invoices/[0-9a-f-]{36}\.pdf$`. Dedicated bucket private ACL.
**OWASP:** A01:2021, A03:2021, API1:2023.

### MED-08 — WhatsApp webhook unbounded payload size
**Risk:** Flooder can OOM queue.
**Location:** `app/Http/Controllers/Webhooks/WhatsappWebhookController.php:71-78`
**Recommendation:** Reject body >256 KB (413). Cap `messages[].text.body` to 4096 chars before persist.
**OWASP:** API4:2023.

---

### LOW-01 — `Log::info('AdminAccessGate hit')` verbose every request
Demote to debug.

### LOW-02 — `Auth::login()` should use explicit guard
Use `Auth::guard('web')->login(...)`.

### LOW-03 — `MoneyCast::set` accepts array from any source
Guard array path with `app()->runningUnitTests()`.

### LOW-04 — `LeakGuard::UUID_V4_REGEX` constant misleading
Rename to `UUID_ANY_REGEX` + update docblock.

---

## Cross-cutting observations

- CI rule banning `DB::raw` outside `App\Reports\*` not located — implement if missing.
- Mobile token revocation on user deactivation: no observer wires `User::saved` to `$user->tokens()->delete()` when `is_active` flips to false.
- `AUDIT_HMAC_KEY` rotation: confirm runbook documents chain invalidation.
- Apply `throttle:60,1` globally to `/api/v1/*` + stricter limits per family.

## Files reviewed (40+)

Bootstrap + middleware + controllers (Auth/AI/Webhooks/Customer/Invoice) + Models (Customer/User/Sale/AiSetting/WhatsappMessage) + Casts + Services (Xubio/Meta/Llm) + Jobs (WhatsApp ingest) + Form Requests + Rules + routes + config (sanctum/auth/audit/cors/session) + .env.example + docs/PLAN.md.
