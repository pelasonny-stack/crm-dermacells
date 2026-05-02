<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AIController;
use App\Http\Controllers\Api\V1\AlertsController;
use App\Http\Controllers\Api\V1\AuthorizationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerWhatsappController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DistributorFinanceController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group.
|
| All routes are prefixed with /api/v1/ as per the CRM Dermacells spec.
| See PLAN.md for full endpoint list per phase.
|
| Mobile (Expo) clients hit /api/v1/auth/oauth/{provider} with
| `X-Client-Type: mobile` (or `?client=mobile`) and receive a Sanctum
| Personal Access Token in response.
|
*/

// =============================================================================
// Phase 12 — WhatsApp Webhook (public — no auth, no v1 prefix)
//
// Meta Cloud API requires the endpoint to respond to:
//   GET  /api/webhooks/whatsapp — hub.challenge verification
//   POST /api/webhooks/whatsapp — event delivery
//
// The webhook.whatsapp.signature middleware validates the HMAC-SHA256 header
// on POST requests and passes GET requests through unconditionally.
// These routes deliberately sit OUTSIDE the v1 group — they are public and
// must never require Sanctum authentication (Meta has no auth header).
// =============================================================================
Route::middleware('webhook.whatsapp.signature')->group(function (): void {
    Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');

    Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'receive'])
        ->name('webhooks.whatsapp.receive');
});

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));

    // OAuth — mobile flow (web flow lives in routes/web.php).
    Route::post('/auth/oauth/{provider}', [OAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'microsoft'])
        ->name('api.auth.oauth.callback');

    // Logout — revokes the calling token only. Other devices stay signed in.
    Route::delete('/auth/token', [OAuthController::class, 'revokeToken'])
        ->middleware('auth:sanctum')
        ->name('api.auth.token.revoke');

    // Current authenticated user — shared by web session and mobile PAT flows.
    // The 'idle' middleware enforces the 30-minute idle window for web sessions;
    // PAT requests (no session last_activity) are transparently unaffected.
    Route::get('/me', function (\Illuminate\Http\Request $request) {
        $user = $request->user();

        return response()->json([
            'id'    => $user->getKey(),
            'email' => $user->getAttribute('email'),
            'role'  => $user->getAttribute('role'),
        ]);
    })->middleware(['auth:sanctum', 'idle'])->name('api.me');

    // -------------------------------------------------------------------------
    // Phase 3 — Customers module (§3)
    // All routes below require Sanctum authentication and have RLS enforced
    // by SetPostgresRlsContext middleware (applied globally in bootstrap/app.php).
    // -------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'idle'])->group(function (): void {
        // Customer CRUD
        Route::apiResource('customers', CustomerController::class);

        // Nested: scheduled actions per customer (sub-resource, not full apiResource
        // because updates/deletes on actions are resolved via customer context)
        Route::get(
            'customers/{customer}/scheduled-actions',
            [CustomerController::class, 'scheduledActionsIndex']
        )->name('customers.scheduled-actions.index');

        Route::post(
            'customers/{customer}/scheduled-actions',
            [CustomerController::class, 'scheduledActionsStore']
        )->name('customers.scheduled-actions.store');

        // -------------------------------------------------------------------------
        // Phase 4 — Stock module (§4, §8)
        // -------------------------------------------------------------------------

        // Central stock (Director only — access enforced inside controller)
        Route::get('stock/central', [StockController::class, 'centralIndex'])
            ->name('stock.central.index');

        Route::post('stock/central/imports', [StockController::class, 'registerImport'])
            ->name('stock.central.imports');

        Route::post('stock/central/dispatches', [StockController::class, 'dispatchFromCentral'])
            ->name('stock.central.dispatches');

        // Distributor stock (Director sees all; Distributor sees own; Seller forbidden)
        Route::get('stock/distributor', [StockController::class, 'distributorIndex'])
            ->name('stock.distributor.index');

        // Seller stock (Director all; Distributor zone via RLS; Seller own)
        Route::get('stock/seller', [StockController::class, 'sellerIndex'])
            ->name('stock.seller.index');

        // Redistribution: Distributor → Seller
        Route::post('stock/redistribute', [StockController::class, 'redistribute'])
            ->name('stock.redistribute');

        // -------------------------------------------------------------------------
        // Phase 5 — Sales module (§5)
        // POST /sales is wrapped in the 'idempotent' middleware (Idempotency-Key header).
        // -------------------------------------------------------------------------

        // Cursor-paginated list
        Route::get('sales', [SaleController::class, 'index'])
            ->name('sales.index');

        // Create draft — idempotent (Stripe pattern)
        Route::post('sales', [SaleController::class, 'store'])
            ->middleware('idempotent')
            ->name('sales.store');

        // Show single sale
        Route::get('sales/{sale}', [SaleController::class, 'show'])
            ->name('sales.show');

        // State transitions
        Route::patch('sales/{sale}/confirm', [SaleController::class, 'confirm'])
            ->name('sales.confirm');

        Route::patch('sales/{sale}/deliver', [SaleController::class, 'deliver'])
            ->name('sales.deliver');

        // Cancel (DELETE semantics — returns 204)
        Route::delete('sales/{sale}', [SaleController::class, 'destroy'])
            ->name('sales.destroy');

        // Partial returns
        Route::post('sales/{sale}/returns', [SaleController::class, 'initiateReturn'])
            ->name('sales.returns.store');

        Route::patch('sales/{sale}/returns/{return}/confirm', [SaleController::class, 'confirmReturn'])
            ->name('sales.returns.confirm');

        // ---------------------------------------------------------------------
        // Phase 9 — Comisiones Vendedor (§12.2)
        //
        // Vendedor / Distribuidor: sees own accrual.
        // Director: forbidden on /me and /me/breakdown (§12.3); allowed on
        //           /{seller_id} to inspect any seller.
        //
        // Route ordering matters: /me/breakdown must be defined before
        // /{seller_id} so Laravel does not try to resolve 'me' as a UUID.
        // ---------------------------------------------------------------------
        Route::get('commissions/me/breakdown', [\App\Http\Controllers\Api\V1\CommissionController::class, 'breakdown'])
            ->name('commissions.me.breakdown');

        Route::get('commissions/me', [\App\Http\Controllers\Api\V1\CommissionController::class, 'me'])
            ->name('commissions.me');

        Route::get('commissions/{seller_id}', [\App\Http\Controllers\Api\V1\CommissionController::class, 'show'])
            ->name('commissions.seller');

        // -------------------------------------------------------------------------
        // Phase 12 — Customer WhatsApp history (§17)
        //
        // RLS on whatsapp_threads / whatsapp_messages enforces visibility:
        //   Seller   → only assigned customers' threads
        //   Distributor → zone customers' threads
        //   Director → all threads
        // -------------------------------------------------------------------------
        Route::get(
            'customers/{customer}/whatsapp/threads',
            [CustomerWhatsappController::class, 'threads']
        )->name('customers.whatsapp.threads');

        Route::get(
            'customers/{customer}/whatsapp/messages',
            [CustomerWhatsappController::class, 'messages']
        )->name('customers.whatsapp.messages');

        // -------------------------------------------------------------------------
        // Phase 11 — Autorizaciones (§13)
        //
        // POST   /authorizations              → Seller/Distributor submits request
        // GET    /authorizations              → Director: all pending; others: own
        // PATCH  /authorizations/{id}/resolve → Director approves/rejects
        // -------------------------------------------------------------------------
        Route::get('authorizations', [AuthorizationController::class, 'index'])
            ->name('authorizations.index');

        Route::post('authorizations', [AuthorizationController::class, 'store'])
            ->name('authorizations.store');

        Route::patch('authorizations/{authorizationRequest}/resolve', [AuthorizationController::class, 'resolve'])
            ->name('authorizations.resolve');

        // -------------------------------------------------------------------------
        // Phase 7 — Cobranzas (§7)
        //
        // POST   /payments                                → register payment (idempotent)
        // GET    /payments                                → list (sale_id, customer_id, date range)
        // DELETE /payments/{id}                           → reverse payment (Director only)
        // GET    /customers/{id}/credit-balances          → list saldo a favor
        // POST   /customers/{id}/credit-balances/{cbId}/apply → apply to sale (Director only)
        // GET    /customers/{id}/account-balance          → current ARS+USD balance
        // GET    /sellers/me/cash-owed                    → Seller cash pending by destination
        // -------------------------------------------------------------------------

        Route::get('payments', [PaymentController::class, 'index'])
            ->name('payments.index');

        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware('idempotent')
            ->name('payments.store');

        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])
            ->name('payments.destroy');

        Route::get('customers/{customer}/credit-balances', [PaymentController::class, 'creditBalancesIndex'])
            ->name('customers.credit-balances.index');

        Route::post('customers/{customer}/credit-balances/{creditBalance}/apply', [PaymentController::class, 'applyCreditBalance'])
            ->name('customers.credit-balances.apply');

        Route::get('customers/{customer}/account-balance', [PaymentController::class, 'accountBalance'])
            ->name('customers.account-balance');

        Route::get('sellers/me/cash-owed', [PaymentController::class, 'cashOwed'])
            ->name('sellers.me.cash-owed');

        // ---------------------------------------------------------------------
        // Phase 10 — Evolution + Alertas (§10, §15)
        // ---------------------------------------------------------------------

        // Own alerts (RLS-filtered by app.user_id at Postgres layer)
        Route::get('alerts/me', [AlertsController::class, 'me'])
            ->name('alerts.me');

        // Mark single alert as read
        Route::patch('alerts/{id}/read', [AlertsController::class, 'markRead'])
            ->name('alerts.read');

        // Alert type catalog — Director only (enforced inside controller)
        Route::get('alerts/types', [AlertsController::class, 'types'])
            ->name('alerts.types');

        // ---------------------------------------------------------------------
        // Phase 8 — Distribuidor financiero (§9)
        //
        // Distributor self-service:
        //   GET  /distributors/me/account              → own balance + margin
        //   GET  /distributors/me/settlements           → own rendiciones
        //   POST /distributors/me/settlements           → initiate rendición
        //   GET  /distributors/me/commission-config     → per-seller % configured
        //   PUT  /distributors/me/commission-config/{sellerId}/{zoneId}
        //   GET  /distributors/me/commission-payments   → pending/paid commissions
        //   GET  /distributors/me/preferred-costs       → own preferred cost config
        //
        // Shared:
        //   PATCH /distributors/settlements/{id}/confirm   → Director confirms
        //   PATCH /distributors/commission-payments/{id}/pay → Distributor records payment
        //
        // Director admin:
        //   GET /admin/distributors/{id}/preferred-costs
        //   PUT /admin/distributors/{id}/preferred-costs/{productId}
        // ---------------------------------------------------------------------

        // Distributor self-service
        Route::get('distributors/me/account', [DistributorFinanceController::class, 'myAccount'])
            ->name('distributors.me.account');

        Route::get('distributors/me/settlements', [DistributorFinanceController::class, 'mySettlements'])
            ->name('distributors.me.settlements.index');

        Route::post('distributors/me/settlements', [DistributorFinanceController::class, 'submitSettlement'])
            ->name('distributors.me.settlements.store');

        Route::get('distributors/me/commission-config', [DistributorFinanceController::class, 'myCommissionConfig'])
            ->name('distributors.me.commission-config.index');

        Route::put('distributors/me/commission-config/{sellerId}/{zoneId}', [DistributorFinanceController::class, 'setCommissionConfig'])
            ->name('distributors.me.commission-config.set');

        Route::get('distributors/me/commission-payments', [DistributorFinanceController::class, 'myCommissionPayments'])
            ->name('distributors.me.commission-payments.index');

        Route::get('distributors/me/preferred-costs', [DistributorFinanceController::class, 'myPreferredCosts'])
            ->name('distributors.me.preferred-costs.index');

        // Shared: confirm settlement (Director) and record commission payment (Distributor)
        Route::patch('distributors/settlements/{id}/confirm', [DistributorFinanceController::class, 'confirmSettlement'])
            ->name('distributors.settlements.confirm');

        Route::patch('distributors/commission-payments/{id}/pay', [DistributorFinanceController::class, 'recordCommissionPaid'])
            ->name('distributors.commission-payments.pay');

        // Director admin endpoints
        Route::get('admin/distributors/{id}/preferred-costs', [DistributorFinanceController::class, 'adminPreferredCosts'])
            ->name('admin.distributors.preferred-costs.index');

        Route::put('admin/distributors/{id}/preferred-costs/{productId}', [DistributorFinanceController::class, 'adminSetPreferredCost'])
            ->name('admin.distributors.preferred-costs.set');

        // ---------------------------------------------------------------------
        // Phase 6 — Billing (§6 Facturación Xubio)
        //
        // POST   /invoices                          → initiate invoice (idempotent, 202)
        // GET    /invoices/{invoice}                → invoice detail
        // POST   /invoices/{invoice}/credit-notes   → manual NC (Director-initiated)
        // GET    /invoices/{invoice}/pdf            → proxy PDF from S3
        //
        // Most NCs (§5.8 partial returns) are emitted automatically via the
        // OnPartialReturnAwaitingCreditNote listener — the manual NC endpoint
        // is the rare Director override path.
        // ---------------------------------------------------------------------

        Route::post('invoices', [InvoiceController::class, 'store'])
            ->middleware('idempotent')
            ->name('invoices.store');

        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
            ->name('invoices.show');

        Route::post('invoices/{invoice}/credit-notes', [InvoiceController::class, 'storeCreditNote'])
            ->name('invoices.credit-notes.store');

        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
            ->name('invoices.pdf');

        // ---------------------------------------------------------------------
        // Phase 13 — AI Assistant (§11)
        //
        // All AI endpoints sit behind the 'ai.cap' middleware which enforces:
        //   - global switch (AiSetting::current()->global_enabled)
        //   - per-user override (AiUserOverride.enabled = false → 403)
        //   - monthly token cap (429 AI_TOKEN_CAP_EXCEEDED)
        //
        // Endpoints:
        //   POST /ai/ask                                — natural language Q&A (SSE)
        //   POST /ai/customers/{customer}/suggest-next  — structured next action
        //   GET  /ai/digest/me                          — daily digest (cached 24h)
        //   GET  /ai/usage/me                           — current month token + cost
        // ---------------------------------------------------------------------
        Route::middleware('ai.cap')->prefix('ai')->group(function (): void {
            Route::post('ask', [AIController::class, 'ask'])
                ->name('ai.ask');

            Route::post('customers/{customer}/suggest-next', [AIController::class, 'suggestNext'])
                ->name('ai.customers.suggest-next');

            Route::get('digest/me', [AIController::class, 'digest'])
                ->name('ai.digest.me');

            Route::get('usage/me', [AIController::class, 'usage'])
                ->name('ai.usage.me');

            // Phase 13 — §11.4: Director-only reassignment suggestions.
            Route::post('reassignments/suggest', [AIController::class, 'suggestReassignments'])
                ->name('ai.reassignments.suggest');
        });

        // -------------------------------------------------------------------------
        // Phase 14 — Dashboards (§14)
        //
        // GET /dashboards/me                                → role-dispatched dashboard
        // GET /dashboards/director/executive               → Director-only (same + docs)
        // GET /dashboards/director/portfolio-health?months → drill-down
        // GET /dashboards/director/zone-ranking            → drill-down
        // POST   /devices                                   → register FCM device
        // DELETE /devices/{device}                          → unregister FCM device
        // -------------------------------------------------------------------------
        Route::get('dashboards/me', [DashboardController::class, 'me'])
            ->name('dashboards.me');

        Route::get('dashboards/director/executive', [DashboardController::class, 'executive'])
            ->name('dashboards.director.executive');

        Route::get('dashboards/director/portfolio-health', [DashboardController::class, 'portfolioHealth'])
            ->name('dashboards.director.portfolio-health');

        Route::get('dashboards/director/zone-ranking', [DashboardController::class, 'zoneRanking'])
            ->name('dashboards.director.zone-ranking');

        // FCM device registration (§15 mobile scaffold — defined in Phase 14)
        Route::post('devices', [DeviceController::class, 'store'])
            ->name('devices.store');

        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])
            ->name('devices.destroy');
    });
});
