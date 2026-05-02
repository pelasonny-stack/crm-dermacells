# CRM Dermacells v4.0 — Plan de Implementación

**Spec fuente:** `~/Downloads/CRM_Dermacells_v4.0.docx` (extraído a `/tmp/dermacells.txt`, 19 secciones, abril 2026)
**Stack confirmado:** Laravel 12 (PHP 8.3+) headless API · React PWA · Expo (React Native + EAS) · PostgreSQL 16 · Redis · AWS sa-east-1
**Plan generado:** 2026-05-02 vía 7 subagentes paralelos (laravel-specialist, php-pro, postgres-pro, api-designer, ai-engineer, security-engineer, payment-integration) + 7 de Phase 0 inicial (research-analyst x2, backend-developer, mobile-developer, postgres-pro, security-engineer, ai-engineer)

---

## Phase 0 — Documentation Discovery (resumen consolidado)

### Decisiones de stack ratificadas

| Componente | Decisión | Justificación |
|---|---|---|
| Backend | **Laravel 12 + PHP 8.3** | Elección del usuario sobre FastAPI/Node |
| ORM | Eloquent + Query Builder en aggregations | Eloquent para CRUD; DB facade para comisiones/evolución |
| Cola | **Horizon (Redis)** | 4 colas por prioridad: critical / high / default / low |
| Scheduler | `routes/console.php` (Laravel 12) | Reemplaza `Kernel.php` legacy |
| WebSockets | **Laravel Reverb** | Para web PWA. Móvil usa solo FCM |
| Push móvil | **kreait/laravel-firebase** (FCM) | Wrapper oficial Expo Notifications encima |
| Admin panel | **Filament v3.2** | Cubre §16 sin frontend custom |
| Money | **brick/money + brick/math** | Inmutable, currency-safe, throws en mismatch |
| Storage money DB | `NUMERIC(18,4)` + `currency CHAR(3)` + `exchange_rate_id` FK | NO BIGINT minor units |
| Auth web | **Sanctum SPA cookie** | 8h absolute, 30min idle |
| Auth móvil | **Sanctum Personal Access Token** + biométrico OS | 30 días TTL, en `expo-secure-store` |
| OAuth | **Socialite** Google + `socialiteproviders/microsoft` | PKCE + tenant restriction (`hd`/`tid`) |
| Token refresh | NO refresh token. Re-OAuth en 401 | OAuth 2.1 BCP §6.1 |
| RLS | **Hybrid: Postgres RLS + `SET LOCAL` por transacción + middleware** | Defense-in-depth sobre Eloquent scopes |
| Pgbouncer | **transaction mode** + `PDO::ATTR_EMULATE_PREPARES=true` | Compatibilidad PHP-FPM corto + SET LOCAL |
| Audit log | REVOKE UPDATE/DELETE + BEFORE trigger + particiones mensuales + HMAC chain en Observer | §16.13 inmutabilidad |
| Móvil | **Expo (React Native) + EAS Build** | Sobre Capacitor por perf de listas + alertas push first-class |
| Lib biométrica | `expo-local-authentication` | OS-delegado |
| OAuth móvil | `expo-auth-session` | ASWebAuthenticationSession / Custom Tabs |
| API style | REST `/api/v1/` + `spatie/laravel-data` DTOs | Sobre JSON:API por simplicidad |
| OpenAPI gen | **Scribe** (`knuckleswtf/scribe`) | Sobre L5-Swagger |
| TS client | `openapi-typescript` + `openapi-fetch` | Compartido React PWA + Expo en monorepo package |
| Errores | RFC 7807 `application/problem+json` | Con `code` business + `meta.permissions` por response |
| Idempotencia | `Idempotency-Key` header en POST /sales /payments /invoices | Stripe pattern, TTL 24h |
| Secrets | **AWS Secrets Manager** + caché Redis 5min | Sobre Doppler/sops |
| IA SDK | **`openai-php/client` + Http facade directo a Anthropic** | NO existe SDK PHP oficial Anthropic 2026 |
| IA streaming | `response()->eventStream()` (Laravel 11+ nativo) | SSE para queries NL |

### APIs externas — hallazgos críticos

**Xubio** (`https://xubio.com/API/1.1/`):
- OAuth2 client_credentials, token TTL 3600s (refresh proactivo a los 50min)
- Endpoints: `POST /FacturaVenta`, `POST /NotaCreditoVenta` (con `idComprobanteAsociado`), `GET /FacturaVenta/{id}`, `GET /FacturaVenta/{id}/pdf`, `GET/POST /Cliente`
- Sin sandbox confirmado (preguntar a soporte)
- AFIP timeout pitfall: CAE otorgado pero Xubio timeout → retry crea factura duplicada. Mitigación: `external_ref` + `ReconcileXubioInvoiceJob` + `ShouldBeUnique`
- Sin webhooks. Reconciliación nightly polling

**BCRA Estadísticas Cambiarias** (`https://api.bcra.gob.ar/estadisticascambiarias/v1.0/`):
- Sin auth, público
- `GET /Cotizaciones` (último cierre) o `/Cotizaciones/{codMoneda}?fechaDesde&fechaHasta` (histórico)
- USD vendedor en `tipoCotizacion.venta`
- Cierre publicado ~17:30-18:00 ART. Antes de eso devuelve día anterior (correcto per "cierre anterior")
- Fallback §16.7: si API 5xx → último TC del historial + warning explícito al usuario

**WhatsApp Business**: Decisión = **Meta Cloud API directo** (no Twilio, no 360dialog)
- 100% inbound-driven (service window) → costo USD 0.00 por mensaje
- Twilio agrega $0.005/msg sin beneficio funcional para este caso
- 360dialog cobra EUR 19+/mes innecesario
- Sin backfill: historial WA solo desde fecha de go-live
- **Bloqueante:** Meta Business Verification en Argentina (1-10 días) — empezar antes del código

### Modelo de datos — resumen (~33 entidades)

Núcleo: `users`, `zones`, `customers`, `customer_billing_entities`, `customer_contacts`, `customer_categories` (A/B/C/D), `payment_terms`, `products`, `price_references`, `distributor_preferred_cost`, `commission_scale` (versionada), `exchange_rates`.

Stock: `central_stock`, `stock_lots` (importaciones con vencimiento), `stock_movements` (8 tipos), `distributor_stock`, `seller_stock`.

Ventas: `sales` (estados borrador/confirmada/entregada/cancelada + `delegated_delivery`), `sale_items`, `sales_status_history`.

Facturación: `invoices` (link Xubio + CAE), `credit_notes` (con `sale_item_id` para parciales).

Cobros: `payments` (5 medios + cash_destination), `customer_account_balances` (dual ARS/USD), `customer_credit_balances` (saldo a favor).

Distribuidor: `distributor_account`, `distributor_settlements`, `commissions`.

Engine: `purchase_evolution_metrics` (cálculo nightly), `scheduled_actions`.

Auth/audit: `authorization_requests`, `alerts`, `audit_log` (particionada mensual, append-only, HMAC chain).

IA/WA: `ai_settings` (single-row), `ai_usage`, `model_pricing`, `whatsapp_threads`, `whatsapp_messages` (body encrypted), `configurations`.

### Riesgos críticos identificados

1. **Meta Business Verification AR**: lead time 1-10 días hábiles. Iniciar Día 1.
2. **Xubio sin sandbox confirmado**: bloquea testing real de facturación. Contactar soporte Xubio Día 1.
3. **AFIP timeout duplicate invoice**: mitigación obligatoria (`ReconcileXubioInvoiceJob` + idempotency key).
4. **BNA API SLA no publicado** (lanzada agosto 2024): fallback obligatorio per §16.7.
5. **IA token cost runaway**: per-user monthly cap + circuit breaker + Director switch global.
6. **`migrate:fresh` en producción** = audit log destruido. Deploy gate en CI obligatorio.
7. **RLS bypass por raw queries**: ban `DB::raw` fuera de `App\Reports\*` allowlist. PHPStan/Larastan rule en CI.
8. **Sanctum token leak desde Expo SecureStore**: device rooting → token exfil. Mitigación: scoped abilities, anomaly detection IP+device, instant revoke.

---

## Phase 1 — Foundation (Sprint 1-2, ~3 semanas)

### Objetivo
Esqueleto Laravel 12 + Postgres + auth + RLS + audit log + Filament shell + CI/CD inicial.

### Qué implementar

1. **Repo + scaffolding**
   - Crear `~/projects/crm-dermacells` (monorepo: `apps/api/` Laravel, `apps/web/` React PWA, `apps/mobile/` Expo, `packages/api-client/` TS shared)
   - `composer create-project laravel/laravel apps/api ^12.0`
   - Estructura DDD-lite: `app/Domain/{Auth,Sales,Stock,Billing,Commissions,WhatsApp,AI,Audit,Customers,Distribution}/`

2. **Postgres 16 + Pgbouncer**
   - RDS Postgres 16 sa-east-1 (sticky=true para read replicas)
   - Pgbouncer transaction mode delante
   - DB roles: `app_role` (CRUD app), `migration_role` (DDL solo en deploys), `report_role` (SELECT lectura), `worker_role` (Horizon, BYPASSRLS=NO)
   - REVOKE UPDATE,DELETE,TRUNCATE ON audit_log FROM app_role

3. **Migrations base + RLS**
   - Tablas core: `users`, `zones`, `customer_categories`, `payment_terms`, `audit_log` (particionada mensual)
   - Habilitar RLS: `ALTER TABLE customers ENABLE ROW LEVEL SECURITY` + policies leyendo `current_setting('app.user_id')` y `current_setting('app.user_role')`
   - BEFORE trigger en `audit_log` que raise exception en UPDATE/DELETE
   - pg_partman setup para particiones mensuales auto

4. **Middleware RLS**
   - `app/Http/Middleware/SetPostgresRlsContext.php` envuelve TODA request en `DB::transaction()` + `DB::statement('SET LOCAL app.user_id = ?', [$userId])` y `app.user_role`
   - Trait `SetsRlsContext` para Jobs Horizon: cada `handle()` lo llama con `actingUserId` serializado en payload

5. **Auth Sanctum + Socialite**
   - `composer require laravel/sanctum laravel/socialite socialiteproviders/microsoft`
   - Configurar SPA stateful domain + PAT abilities por rol
   - OAuthController callback con pre-registered email binding (no JIT)
   - Tenant restriction: validar `tid` (Microsoft) y `hd` (Google) contra allowlist
   - Middleware `CheckIdleTimeout` (30min)
   - Endpoint `DELETE /auth/token` (logout móvil) + `$user->tokens()->delete()` en deactivation

6. **Audit log Observer + HMAC chain**
   - `App\Domain\Audit\Observers\AuditObserver` con HMAC-SHA256 encadenado
   - HMAC key en Secrets Manager
   - Verifier nightly job que recorre cadena y page si mismatch
   - Registrar Observer en cada model sensible vía `User::observe(AuditObserver::class)` en `AppServiceProvider::boot()`

7. **Secrets Manager**
   - `composer require aws/aws-sdk-php`
   - `SecretsServiceProvider` con caché Redis 5min
   - Bootstrap antes de providers que dependen de config
   - Secrets en scope: `XUBIO_TOKEN`, `WA_TOKEN`, `OPENAI_KEY`, `ANTHROPIC_KEY`, `AUDIT_HMAC`, `APP_KEY`, OAuth client secrets

8. **Filament v3.2 shell**
   - `composer require filament/filament:^3.2`
   - Panel `/admin` con guard separado
   - Política `Director-only`: middleware verifica `auth()->user()->role === 'director'`
   - IP allowlist + step-up re-OAuth para entrar (`Filament::serving` middleware)

9. **Testing setup (Pest 3)**
   - `composer require pestphp/pest pestphp/pest-plugin-laravel --dev`
   - Factories base: `UserFactory`, `ZoneFactory`
   - `RefreshDatabase` trait
   - Cross-role denial tests: cada modelo bajo RLS debe tener test asserting Vendedor no ve datos de otro Vendedor

10. **CI/CD inicial (GitHub Actions)**
    - Lint: Pint + Larastan + PHPStan level 8
    - Tests: Pest con `--coverage --min=85`
    - Deploy gate: refusa `migrate:fresh` y `migrate:rollback` si `APP_ENV=production`
    - Scribe: regenera `openapi.yaml` y rompe PR si hay diff sin commit

### Documentación a referenciar (copiar patrones de aquí)

- Laravel 12 structure: https://laravel.com/docs/12.x/structure
- Sanctum SPA: https://laravel.com/docs/12.x/sanctum#spa-authentication
- Sanctum PAT: https://laravel.com/docs/12.x/sanctum#api-token-authentication
- Socialite: https://laravel.com/docs/12.x/socialite
- Postgres RLS: https://www.postgresql.org/docs/16/ddl-rowsecurity.html
- Laravel transactions + savepoints: https://laravel.com/docs/12.x/database#database-transactions
- pg_partman: https://github.com/pgpartman/pg_partman
- Pgbouncer features: https://www.pgbouncer.org/features.html
- Filament panels: https://filamentphp.com/docs/3.x/panels/installation
- Pest: https://pestphp.com/docs/installation

### Verification checklist
- [ ] `php artisan test` pasa con coverage ≥85%
- [ ] Test cross-role: Vendedor A logged in NO ve customers asignados a Vendedor B (RLS funciona)
- [ ] Test audit: UPDATE directo SQL en `audit_log` raises exception (trigger funciona)
- [ ] Test particiones: insert con `occurred_at` next month va a partición correcta auto-creada
- [ ] Sanctum SPA cookie auth funciona con CSRF init
- [ ] Sanctum PAT funciona con Bearer header desde cliente externo
- [ ] OAuth Google rechaza email no pre-registrado (403)
- [ ] OAuth Microsoft valida `tid` allowlist
- [ ] Filament `/admin` requiere role=director + IP allowlist
- [ ] Deploy gate falla si CI intenta `migrate:fresh` con `APP_ENV=production`
- [ ] HMAC chain audit_log: verifier job detecta tampering manual

### Anti-patterns a evitar
- ❌ NO usar `JWT` (decisión: opaque tokens Sanctum por revocación instantánea)
- ❌ NO `migrate:fresh`/`migrate:reset` en producción jamás
- ❌ NO `DB::raw(...)` fuera de `App\Reports\*` namespace allowlisted
- ❌ NO `Model::update($request->all())` (mass assignment) — usar Form Requests + servicios dedicados
- ❌ NO `Auth::loginUsingId()` en jobs (worker no debe impersonar Director)
- ❌ NO commits de secrets — todo via Secrets Manager
- ❌ NO usar pgbouncer session mode con PHP-FPM
- ❌ NO `PDO::ATTR_EMULATE_PREPARES=false` con pgbouncer transaction mode (named statements rompen)
- ❌ NO crear JIT users en OAuth callback — solo pre-registered

---

## Phase 2 — Master Data + TC (Sprint 3, ~1.5 semanas)

### Objetivo
Datos maestros configurables + integración BCRA con fallback.

### Qué implementar
1. Models + migrations: `customer_categories` (A/B/C/D con `director_only=true` para D), `payment_terms` (Contado/15/30/45/60), `products` (Dermal/Pink/Capillary/Biomask, USD 750 base), `commission_scale` (versionada con `effective_from`), `distributor_preferred_cost` (modalidad fixed/discount), `exchange_rates`
2. `MoneyCast` Eloquent custom cast (compound: `amount` + `currency` columns → `Brick\Money\Money`)
3. `FetchBCRAExchangeRateJob` con `Schedule::job(...)->dailyAt('19:00')->timezone('America/Argentina/Buenos_Aires')` + `WithoutOverlapping`
4. Fallback: si BCRA 5xx → leer último de `exchange_rates` + `Director` notification + warning marca `source='fallback'`
5. Director override manual TC desde Filament
6. Filament Resources para todas las entidades anteriores
7. CRUD endpoints `/api/v1/products`, `/api/v1/zones`, `/api/v1/categories`, etc. (mostly Director-only)

### Docs
- BCRA API: https://estadisticas-cambiarias.bcra.apidocs.ar/
- Brick Money: https://github.com/brick/money
- Laravel custom casts: https://laravel.com/docs/12.x/eloquent-mutators#custom-casts

### Verification
- [ ] `FetchBCRAExchangeRateJob` corre y persiste TC con `source='api_bna'`
- [ ] Si mockeo BCRA 5xx, job persiste con `source='fallback'` y dispara notification
- [ ] `MoneyCast` hidrata correctamente `numeric(18,4)+CHAR(3)` ↔ `Money` value object
- [ ] Sumar Money ARS + Money USD throws `MoneyMismatchException`
- [ ] Filament permite override manual de TC con audit_log entry

### Anti-patterns
- ❌ NO floats para money — siempre `Brick\Money\Money`
- ❌ NO storage de TC convertido (commission USD-equivalent NUNCA se persiste — siempre se calcula en query time)

---

## Phase 3 — Clientes (Sprint 4, ~2 semanas)

### Objetivo
Módulo §3 completo: CRUD + razones sociales + contactos + scheduled_actions.

### Qué implementar
1. `customers` model con cast `MoneyCast` para `reference_price_usd` + `reference_price_unit_usd`
2. `CuitFormatRule` (11 dígitos + checksum modulo-11)
3. `UniqueCuitRule` que en violation devuelve "CUIT pertenece a Vendedor X, contactar Director" (§3.9)
4. `customer_billing_entities` (razones sociales, una principal) + `customer_contacts` (con birthday push 8AM)
5. `scheduled_actions` (§3.8) con scheduler que dispara push el día indicado
6. Baja bloqueada (§3.10): policy `delete()` chequea saldo pendiente, ventas Borrador/Confirmada, cobros vencidos. Director puede forzar con motivo en audit_log
7. Asignación masiva al desactivar Vendedor: ventas abiertas y cobros pendientes quedan bajo nombre del Vendedor desactivado, visibles para nuevo Vendedor
8. Endpoints REST `/api/v1/customers/*` con `meta.permissions` por response
9. Filament Resource con tabs: principal, razones sociales, contactos, acciones programadas, historial WhatsApp (placeholder phase 12)

### Verification
- [ ] Crear cliente con CUIT inválido (checksum) → 422 `CUIT_INVALID`
- [ ] Crear cliente con CUIT duplicado → 422 con info del Vendedor asignado
- [ ] Vendedor A no puede ver cliente de Vendedor B (RLS)
- [ ] Distribuidor ve solo clientes de su zona (RLS)
- [ ] Director ve categoría D; Vendedor/Distribuidor 403
- [ ] Baja bloqueada con saldo pendiente → 409 `CUSTOMER_HAS_PENDING_BALANCE`
- [ ] `scheduled_action` programada para mañana → mañana 8AM dispara push al Vendedor

### Anti-patterns
- ❌ NO permitir alta de CUIT duplicado bajo ninguna circunstancia (§3.9)
- ❌ NO reasignar ventas abiertas al desactivar Vendedor — quedan bajo su nombre

---

## Phase 4 — Stock Central + Distribución (Sprint 5, ~2 semanas)

### Objetivo
§4 + §8: bodega central + flujo despacho + stock distribuidor/vendedor + alarmas.

### Qué implementar
1. `central_stock`, `stock_lots`, `stock_movements` (8 tipos enum), `distributor_stock`, `seller_stock`
2. Acciones: `RegisterImportAction` (Director only), `DispatchToDistributorAction`, `DispatchDirectToSellerAction` (zonas directas), `RedistributeAction` (Distribuidor → Vendedor su zona)
3. Reservas de stock: `ReserveStockOnSaleConfirm`, `CommitStockOnDeliver`, `RollbackStockOnCancel`
4. Validación al crear Borrador (§4.5): warning, no bloqueo. Bloqueo al confirmar.
5. Stock alerts (§8.3): job `MonitorStockMinimumsJob` cada hora dispara push si stock < mínimo
6. Vencimiento de lotes: `LotExpiryAlertJob` daily, X días antes (configurable)
7. Filament Resource para stock central + dashboard widgets

### Verification
- [ ] Despachar > stock disponible → 422 `STOCK_INSUFFICIENT`
- [ ] Crear Borrador con stock insuficiente → 200 con warning, no bloquea
- [ ] Confirmar venta con stock insuficiente → 422
- [ ] Cancelar venta entregada → stock revierte al Vendedor
- [ ] Stock < mínimo → push al Director + Distribuidor de zona

---

## Phase 5 — Ventas (Sprint 6-7, ~3 semanas)

### Objetivo
§5: ciclo completo Borrador→Confirmada→Entregada→Cancelada + entrega delegada + devolución parcial.

### Qué implementar
1. `sales` con state machine + `sales_status_history`
2. `CreateDraftSaleAction`, `ConfirmSaleAction` (reserva stock, valida), `DeliverSaleAction` (commit stock), `CancelSaleAction` (reglas §5.6)
3. Entrega delegada (§5.7): flag `delegated_delivery` por Vendedor (configurable Director). Si activo en venta fuera de zona base → stock se descuenta del Distribuidor de esa zona
4. Devolución parcial (§5.8): Vendedor inicia → Director confirma. Si factura existe, bloqueo hasta NC en Xubio (Phase 6)
5. Cancelación con cobros: solo Director. Genera saldo a favor (Phase 7)
6. Cancelación con factura: bloqueo hasta NC (Phase 6)
7. Endpoints REST `/api/v1/sales/*` con `Idempotency-Key` middleware
8. Filament Resource con state visualizer

### Verification
- [ ] Vendedor cancela venta propia sin cobros → OK
- [ ] Vendedor intenta cancelar venta con cobros → 403 (solo Director)
- [ ] Vendedor intenta cancelar venta con factura → 409 `INVOICE_NC_REQUIRED`
- [ ] Entrega delegada: stock descontado del Distribuidor zona, no del Vendedor
- [ ] Devolución parcial sin factura: stock vuelve al Vendedor actual del cliente, monto a saldo a favor

---

## Phase 6 — Facturación Xubio (Sprint 8, ~2 semanas)

### Objetivo
§6: integración Xubio + idempotencia + reconciliación + NC parcial.

### Qué implementar
1. **Pre-trabajo (BLOQUEANTE):** Confirmar con soporte Xubio: sandbox URL, external_ref field, partial-NC payload, PDF response shape, rate limit headers
2. `XubioClient` macro `Http::xubio()` + `TokenManager` con `Cache::lock` atomic
3. `IssueXubioInvoiceJob` con `external_ref = "sale-{id}-{ulid}"` + `lockForUpdate()` en `invoices`
4. `ReconcileXubioInvoiceJob` con `ShouldBeUnique` + `WithoutOverlapping`
5. `IssueXubioCreditNoteJob` para NC parcial con `idComprobanteAsociado`
6. Horizon supervisor `xubio` queue: maxProcesses 2, tries 3, backoff [10,30,90]
7. `xubio_api_log` table (90d retention) — request/response/latency replay
8. Nightly `ReconcileInvoicesNightlyJob` polling `/FacturaVenta?modifiedSince=yesterday`
9. `XubioClientResolver`: lookup-or-create cliente por CUIT, cachea `xubio_cliente_id` en `customer_billing_entities`
10. PDF storage: `Storage::disk('s3')->put("invoices/{$id}.pdf", $pdf)`

### Docs
- Xubio API (read at runtime): https://xubio.com/API/documentation/index.html
- Laravel HTTP: https://laravel.com/docs/12.x/http-client
- Cache atomic locks: https://laravel.com/docs/12.x/cache#atomic-locks
- Stripe idempotency: https://stripe.com/docs/api/idempotent_requests

### Verification
- [ ] Mock Xubio 200 → `invoices.status='success'` + CAE persistido
- [ ] Mock Xubio timeout → `status='reconciling'` + `ReconcileXubioInvoiceJob` dispatched
- [ ] Reconciliation encuentra factura por external_ref → marca success
- [ ] Reconciliation NO encuentra → marca `failed_manual_review` + Director notification
- [ ] NC parcial bloquea cancelación hasta emisión exitosa
- [ ] Token refresh atomic lock previene stampede (test concurrente)

### Anti-patterns
- ❌ NO retry POST /FacturaVenta automático sin reconciliación previa (riesgo duplicate invoice)
- ❌ NO hardcodear `xubio_cliente_id` (siempre lookup por CUIT primero)

---

## Phase 7 — Cobranzas + Cuenta Corriente (Sprint 9, ~2 semanas)

### Objetivo
§7: cobros multi-medio + cuenta corriente dual ARS/USD + saldo a favor + devolución de cobros.

### Qué implementar
1. `payments` model con `payment_methods` lookup (transferencia Dermacells/Distribuidor, efectivo, tarjeta, cheque)
2. `cash_destination` auto-resuelto (§7.2): si zona tiene Distribuidor → al Distribuidor, sino → Dermacells. UI lo informa
3. `customer_account_balances` dual ARS/USD: cobro ARS acumula ARS, cobro USD acumula USD (NUNCA conversión)
4. Anticipos: flag `is_advance` cuenta en mes del cobro para comisiones
5. `customer_credit_balances` (saldo a favor): se genera al cancelar venta con cobros (sin factura) o tras NC (con factura)
6. Devolución de cobros (§7.6): solo Director, requiere motivo, audit_log
7. Vista "cuánto efectivo debo a cada Distribuidor / a Dermacells" para Vendedor
8. Endpoints REST + Filament

### Verification
- [ ] Cobro efectivo cliente zona con Distribuidor → `cash_destination='distributor'` + `cash_destination_dist_id` set
- [ ] Cobro efectivo cliente zona directa → `cash_destination='dermacells'`
- [ ] Cobros ARS y USD acumulan en columnas separadas, NO se convierten
- [ ] Cancelar venta con cobros sin factura → genera `customer_credit_balances` con `applied_to_sale_id=NULL`
- [ ] Devolución de cobro (Director) registrada con motivo en audit_log

---

## Phase 8 — Distribuidor Financiero (Sprint 10, ~1.5 semanas)

### Objetivo
§9: costo preferencial + comisiones a vendedores + cuenta corriente Distribuidor + rendiciones.

### Qué implementar
1. `DistribuidorMarginService` (brick/money): preferred cost (fixed o %) por producto
2. `commissions` table con `commission_pct` libre por Distribuidor → Vendedor de su zona
3. Comisiones aplican TODAS las ventas zona (incluso ventas de Director con flag — §9.2)
4. Job `RecalculateDistributorAccountJob` que computa: ventas zona − costo preferencial − comisiones pagadas = saldo neto
5. `DistributorSettlementsController`: rendición iniciada por Distribuidor, confirmada por Director
6. Filament Resource con dashboard de saldo a rendir tiempo real

### Verification
- [ ] Distribuidor con modalidad fixed USD 500 / discount 20% → margen calculado correctamente
- [ ] Vendedor en múltiples zonas: cada Distribuidor fija y paga independiente
- [ ] Rendición pending → Director confirma → balance actualizado

---

## Phase 9 — Comisiones Vendedor (Sprint 11, ~1.5 semanas)

### Objetivo
§12: escala global USD-equivalente + cálculo en tiempo real + tablero Vendedor.

### Qué implementar
1. `CommissionCalculatorService` (mostrado por php-pro):
   - Acumula payments del mes a USD equivalente (cobros ARS / `payment.exchange_rate`)
   - Resuelve tier (≤ USD 7500 = 10%, ≤ USD 11250 = 12%, > = 15%)
   - Aplica tier rate sobre 100% del acumulado
   - Splits final commission proporcional a moneda real del cobro (ARS y USD por separado)
2. Director NO percibe comisiones nunca (§2.4 + §12.3)
3. Endpoint `GET /api/v1/commissions/me` para Vendedor con desglose por zona y moneda
4. Tier umbrales configurables en Filament (§16.5)

### Verification
- [ ] Vendedor con USD 8000 cobrados (mix ARS+USD) → tier 12% sobre 100%
- [ ] Anticipo cuenta en mes del cobro, no de la venta
- [ ] Director con flag activo: 0 commissions calculadas
- [ ] Cuentas corrientes ARS/USD del cliente sin alterar (solo se convierte a USD-equiv para escalón)

---

## Phase 10 — Evolution Engine + Alertas (Sprint 12, ~2 semanas)

### Objetivo
§10 + §15: motor evolución compra + 7 tipos de alertas reactivas.

### Qué implementar
1. `purchase_evolution_metrics` table + `RecomputePurchaseEvolutionJob` nightly
2. Materialized view sobre `sales JOIN sale_items WHERE status='delivered'` agrupada por `(customer_id, product_id)`, `REFRESH CONCURRENTLY` nightly
3. Cálculo de tendencia: comparar últimos 2 intervalos vs promedio histórico
4. Estados: first_purchase, increasing, stable (±20%), decreasing (>20%), scheduled, inactive (>2x frecuencia)
5. Alert dispatcher centralizado: `App\Domain\Alerts\AlertDispatcher` → push (Reverb) + FCM + persist `alerts` table
6. 7 tipos: próximo a vencer ciclo, frecuencia decreciente, cliente inactivo, recuperación, primera compra sin recompra, zona en riesgo (>Y%), acción futura vencida
7. Cumpleaños 8AM, cobros vencidos, stock mínimo, vencimiento lote, venta inusual, autorización pendiente — del §15

### Verification
- [ ] Cliente con 2 compras delivered → metrics generadas
- [ ] Intervalo reciente >120% promedio → estado `decreasing` + alerta
- [ ] Sin compra >2x frecuencia → `inactive` + alerta a Vendedor + Distribuidor
- [ ] Zona con >30% inactivos → alerta a Distribuidor + Director
- [ ] Cliente con scheduled_action vigente → estado `scheduled` (alertas siguen pero etiquetadas)

---

## Phase 11 — Sistema de Autorizaciones (Sprint 13, ~1 semana)

### Objetivo
§13: flujo precio/TC modify request + approval Director.

### Qué implementar
1. `authorization_requests` model con tipo enum, estado pending/approved/rejected
2. Vendedor/Distribuidor `POST /api/v1/authorizations` con `current_value`, `proposed_value`, `reason`
3. Push a TODOS los Directores (canal `private-director`)
4. Cualquier Director resuelve `PATCH /authorizations/{id}/resolve`
5. Operación bloqueada hasta resolución (state guarded en `Sale`/`Invoice` actions)
6. Aprobación desbloquea valor SOLO para esa operación puntual
7. Filament page con queue de pending + approve/reject inline

### Verification
- [ ] Vendedor propone precio modificado → request `pending`, push a 2 Directors
- [ ] Director A aprueba → estado `approved`, Vendedor recibe push, valor desbloqueado
- [ ] Vendedor intenta cerrar venta sin resolución → 409
- [ ] Director B rechaza con motivo → Vendedor recibe push con motivo

---

## Phase 12 — WhatsApp Business API (Sprint 14, ~2 semanas)

### Objetivo
§17: integración Meta Cloud API directo.

### Pre-trabajo (BLOQUEANTE — empezar Phase 1)
- Meta Business Verification (1-10 días AR)
- Phone number procurement
- Display name approval (24-72h)
- Webhook public HTTPS endpoint

### Qué implementar
1. `whatsapp_threads` + `whatsapp_messages` (body con Laravel `encrypted` cast AES-256-GCM)
2. Webhook `POST /api/v1/webhooks/whatsapp` con verificación `hub.challenge` GET + signature validation HMAC SHA256
3. `IngestWhatsAppMessageJob` queueable → match phone con `customers.phone` → persiste en thread
4. Visualización en ficha cliente (read-only) Filament + endpoint `GET /api/v1/customers/{id}/whatsapp-messages`
5. Deep-link outbound desde ficha (no template MVP)
6. Visibilidad: solo Vendedor asignado + roles superiores
7. Documentar: historial WA solo desde fecha go-live (no backfill posible)

### Verification
- [ ] Webhook verification GET responde `hub.challenge`
- [ ] Inbound message persiste en thread del customer matcheado por phone
- [ ] Inbound de phone sin customer → log a tabla "unmatched_whatsapp_messages" para revisión
- [ ] Vendedor B no ve threads de cliente del Vendedor A (RLS)
- [ ] Body encriptado at rest (verificar columna ilegible en SQL directo sin Crypt::decrypt)

---

## Phase 13 — AI Assistant Module (Sprint 15-16, ~3 semanas)

### Objetivo
§11: módulo opcional con provider runtime-configurable.

### Qué implementar
1. `ai_settings` single-row + `ai_usage` + `model_pricing` Eloquent
2. `LLMClient` interface + `AnthropicHttpClient` (vía `Http::macro('anthropic')`) + `OpenAIClient` (`openai-php/laravel`)
3. Prompt structure con caching: system rules (cache_control ephemeral) + customer context (cache_control ephemeral) + user query
4. Customer context fetch: últimas 20 transactions + evolution metrics + últimas 10 WA conversations + notes + customer data — query Postgres directo, NO RAG
5. Anti-leak: system prompt rule + post-response `LeakGuard` walks JSON / regex scans buscando `customer_id` distinto al inyectado → abort + log
6. Function calling para queries NL: tools `query_customers_by_inactivity`, `get_monthly_sales` con `WHERE customer_id=:ctx_customer_id AND vendedor_scope=:user_scope` aplicado server-side
7. Structured output (JSON schema strict) para "siguiente acción sugerida" + "resumen diario"
8. SSE streaming via `response()->eventStream()`
9. `EnforceAiTokenCap` middleware: chequea monthly usage vs cap antes de forward, 429 en breach
10. `RecordAiUsage` queueable job persiste tokens + cost (tabla `ai_usage` joined a `model_pricing`)
11. Daily digest job 7AM precomputa "resumen diario" cacheado
12. Switch global + per-user override en Filament

### Verification
- [ ] Switch global OFF → endpoints AI 503
- [ ] Per-user OFF + global ON → solo ese user 403
- [ ] Token cap excedido → 429 `AI_TOKEN_CAP_EXCEEDED`
- [ ] Response menciona customer_id distinto al injected → abort + log
- [ ] SSE stream funciona end-to-end con curl
- [ ] Cambiar `ai_settings.model` desde Filament cambia provider sin redeploy
- [ ] Anthropic prompt cache hit (verificar `cache_read_input_tokens > 0` en 2da llamada)

### Anti-patterns
- ❌ NO RAG / vector store en MVP
- ❌ NO permitir LLM ejecutar writes a DB
- ❌ NO hardcodear modelo (siempre leer de `ai_settings.model` string libre)
- ❌ NO inyectar contextos de múltiples customers en una llamada (anti-leak)

---

## Phase 14 — Tableros (Sprint 17-18, ~3 semanas)

### Objetivo
§14: 3 tableros (Vendedor, Distribuidor, Director Ejecutivo).

### Qué implementar
1. Endpoint `GET /api/v1/dashboards/me` que server-resuelve shape según rol
2. Vendedor: hoy (alertas, cumpleaños, cobros 48h), mi mes (USD cobrado, comisión, ventas, cobros pendientes), mi operación (stock, confirmadas, autorizaciones)
3. Distribuidor: zona hoy (top 5 urgencia), mi mes (ranking vendedores, evolución 6m), mi cuenta Dermacells, mi stock
4. Director Ejecutivo: pulso (ventas/cobros día, autorizaciones, alertas críticas), el mes (ranking, metas), salud cartera (clientes activos/perdidos 12m, top 10), stock + operación, financiero, sistema (IA tokens, integraciones OK)
5. Reverb private channels para updates real-time
6. React PWA components: charts (Recharts), tablas (TanStack Table), real-time hooks
7. Aggregations heavy via DB facade (no Eloquent hydration)
8. Materialized views para Director ejecutivo (refresh cada 5min)

### Verification
- [ ] Cada rol ve solo su tablero shape
- [ ] Datos en tiempo real via Reverb (test: crear venta → dashboard updates sin refresh)
- [ ] Materialized view refresh 5min, no bloquea reads (CONCURRENTLY)
- [ ] Performance: Director dashboard <800ms p95 con 12 meses data

---

## Phase 15 — Móvil Expo (Sprint 19-20, ~3 semanas)

### Objetivo
§18.3 + §19: app iOS + Android paridad funcional con web.

### Qué implementar
1. `npx create-expo-app apps/mobile --template default-typescript`
2. Configurar EAS Build (`eas build:configure`) iOS + Android
3. `expo-auth-session` para OAuth Google + Microsoft
4. Token Sanctum recibido del backend, store en `expo-secure-store`
5. `expo-local-authentication` gate antes de leer SecureStore
6. `expo-notifications` + FCM: registrar token via `POST /api/v1/devices`
7. Screens MVP: login, tablero rol, lista clientes, ficha cliente con WA + scheduled actions, lista ventas, crear venta (form), cobros, alertas
8. Use FlashList para todas las listas (perf en mid-range AR devices)
9. Compartir TS client con web via `packages/api-client/`
10. EAS Update para OTA (rollback + staged rollout)
11. App Store + Play Store submission via `eas submit`

### Verification
- [ ] Build iOS + Android OK en EAS
- [ ] OAuth flow completo termina en Sanctum token guardado
- [ ] Biométrico requerido para abrir app (segunda vez)
- [ ] Push FCM recibido foreground + background
- [ ] FlashList renderiza 1000 cobros sin jank
- [ ] OTA update via EAS Update funciona

---

## Phase 16 — Filament Config Panel (Sprint 21, ~2 semanas)

### Objetivo
§16 completo en Filament: 13 subsecciones de configuración.

### Qué implementar
Resources/Pages adicionales (sobre los ya creados en fases previas):
1. Gestión usuarios + flag `puede_vender` toggle + reasignación masiva clientes (action en bulk)
2. Gestión zonas + asignación Distribuidor + zonas directas
3. Productos + precio base USD + costo preferencial por Distribuidor
4. Comisiones: escala global UI + ver/modificar % por Distribuidor→Vendedor
5. Condiciones de pago (no eliminable si en uso)
6. Tipo de cambio: ver historial + override manual + fuente API
7. Metas mensuales por Vendedor + copiar mes anterior
8. Alertas y umbrales (stock min, venta inusual, frecuencia, etc.)
9. Asistente IA: switch global, override per-user, provider+model+endpoint+API key (masked) + token monitor
10. Stock central mínimos + alerta vencimiento lote
11. Autorizaciones queue
12. Audit log viewer con filtros + export Excel/CSV/PDF (`pxlrbt/filament-excel` + `barryvdh/laravel-dompdf`)

### Verification
- [ ] Director cambia escala comisiones → audit_log entry + cálculo aplica desde mes siguiente
- [ ] Director enmascara API key (solo últimos 4 char visibles)
- [ ] Audit log exportado a Excel coincide con DB
- [ ] Director NO puede editar audit_log (button hidden + 403 server-side)

---

## Phase 17 — Verification + Hardening + Go-Live (Sprint 22-23, ~3 semanas)

### Qué hacer
1. **Security audit** — penetration-tester / security-auditor agents:
   - OWASP Top 10
   - OWASP LLM Top 10 (prompt injection vía WhatsApp)
   - Sanctum token leak scenarios
   - RLS bypass attempts
2. **Load testing** (k6 / Locust):
   - 50 usuarios concurrentes (3-5x baseline)
   - Dashboards <800ms p95
   - Xubio job queue no se satura
3. **Backup + DR**:
   - RDS automated backups + 7-year retention
   - S3 Object Lock COMPLIANCE para audit log dumps
   - PITR test
4. **Verificar audit log integrity**:
   - HMAC chain verifier nightly job corriendo
   - Manual tampering test → detected
5. **Monitoring**:
   - CloudWatch alarms: queue depth, p95 latency, 5xx rate, AI token cost
   - Sentry para errors backend + móvil + web
   - Pulse o Telescope para dev (no prod)
6. **Documentation**:
   - README per app
   - Runbook ops (Xubio failure, BNA fallback, Meta verification re-issue)
   - User manual por rol
7. **Training**:
   - Sesión Director (configuración + autorizaciones + audit)
   - Sesión Distribuidor (rendiciones + comisiones)
   - Sesión Vendedor (móvil + IA)
8. **Go-live**:
   - Migrar/cargar master data inicial (productos, zonas, vendedores, clientes existentes)
   - Cutover plan: paralelo 2 semanas con sistema legacy si existe

### Anti-patterns
- ❌ NO go-live sin penetration test pasado
- ❌ NO go-live sin DR test verificado
- ❌ NO go-live sin Meta Business Verification cerrada

---

## Estimación total

| Fase | Sprints | Semanas | Subagentes principales |
|---|---|---|---|
| 1 Foundation | 1-2 | 3 | laravel-specialist, postgres-pro, security-engineer, devops-engineer |
| 2 Master data + TC | 3 | 1.5 | laravel-specialist, php-pro |
| 3 Clientes | 4 | 2 | laravel-specialist, ux-researcher (forms) |
| 4 Stock + Distribución | 5 | 2 | laravel-specialist, postgres-pro |
| 5 Ventas | 6-7 | 3 | laravel-specialist, php-pro (state machine) |
| 6 Xubio | 8 | 2 | payment-integration, laravel-specialist |
| 7 Cobranzas | 9 | 2 | laravel-specialist, php-pro |
| 8 Distribuidor financiero | 10 | 1.5 | laravel-specialist, php-pro |
| 9 Comisiones Vendedor | 11 | 1.5 | php-pro, data-engineer |
| 10 Evolution + Alertas | 12 | 2 | data-engineer, postgres-pro, laravel-specialist |
| 11 Autorizaciones | 13 | 1 | laravel-specialist |
| 12 WhatsApp | 14 | 2 | backend-developer, security-engineer |
| 13 AI | 15-16 | 3 | ai-engineer, laravel-specialist |
| 14 Tableros | 17-18 | 3 | react-specialist, data-engineer, postgres-pro |
| 15 Móvil Expo | 19-20 | 3 | expo-react-native-expert, mobile-developer |
| 16 Filament Config | 21 | 2 | laravel-specialist |
| 17 Verification + Go-live | 22-23 | 3 | penetration-tester, qa-expert, sre-engineer, devops-engineer |
| **TOTAL** | **23 sprints** | **~38 semanas (~9 meses)** | |

**MVP cortado** (sin IA, sin tableros avanzados, sin móvil): Phases 1-9 + 14 simplificado + 16 base = ~22 semanas (~5 meses)

---

## Estrategia de Testing

- **Pest 3** test runner
- **Coverage mínimo CI: 85%**
- **Feature tests** por endpoint con `actingAs($user, 'sanctum')` cubriendo cross-role denial
- **Unit tests** para servicios críticos: `CommissionCalculatorService`, `DistribuidorMarginService`, `XubioClient`, `LeakGuard`, `MoneyCast`
- **Integration tests** Xubio sandbox (cuando confirmado) marcados `@group=integration`, no en CI default
- **Browser tests** Pest Browser (Dusk++) para flows críticos web
- **E2E móvil**: Detox o Maestro Studio para Expo
- **Load tests** k6 antes de go-live
- **Chaos** chaos-engineer agent: matar Reverb, BNA timeout sustained, Xubio 5xx — sistema debe degradar graceful

## CI/CD

- **GitHub Actions** workflows:
  - PR check: lint (Pint) + Larastan L8 + Pest + Scribe diff + frontend type-check
  - Main: build images (Docker), push ECR, deploy ECS Fargate via Terraform
- **Ambientes**: local (sail) → dev (auto-deploy main) → staging (manual promote) → prod (manual promote + 2 approvers)
- **Migrations**: gate `migrate:fresh`/`migrate:rollback` blockeado en prod
- **Secrets** inyectados desde AWS Secrets Manager al boot, NO en `.env` prod
- **Horizon** corre en ECS service separado del web
- **Reverb** corre en ECS service separado
- **Postgres**: RDS sa-east-1 con read replica + Pgbouncer en sidecar
- **Backup**: RDS automated daily + S3 Object Lock para audit dumps

## Final Verification (post-go-live)

- [ ] Penetration test report sin findings críticos/altos
- [ ] DR test: restore Postgres + S3 audit en staging desde backup
- [ ] Audit log HMAC chain verifier corre nightly sin gaps
- [ ] BNA fallback testeado en prod (forzar 5xx mock un día)
- [ ] Xubio reconciliation: forzar timeout, verificar reconcile correcto
- [ ] Meta Business Verification activa + display name aprobado
- [ ] Todos los Directores con 2FA en su IdP
- [ ] CloudWatch alarms test: queue depth, 5xx rate, AI token cost
- [ ] Coverage CI ≥85% sostenido
- [ ] Sentry sin errores P0/P1 7 días consecutivos
