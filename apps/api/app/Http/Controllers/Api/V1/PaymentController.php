<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\ApplyCreditBalanceAction;
use App\Actions\Payments\RegisterPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Domain\Payments\Services\CreditBalanceService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ApplyCreditBalanceRequest;
use App\Http\Requests\Payments\RegisterPaymentRequest;
use App\Http\Requests\Payments\ReversePaymentRequest;
use App\Models\Customer;
use App\Models\CustomerAccountBalance;
use App\Models\CustomerCreditBalance;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PaymentController — Phase 7 cobranzas endpoints.
 *
 * ENDPOINT SUMMARY
 * ================
 * GET    /payments                                 → cursor-paginated list (sale_id, customer_id, date range)
 * POST   /payments                                 → register payment (idempotent)
 * DELETE /payments/{id}                            → reverse payment (Director only)
 * GET    /customers/{id}/credit-balances           → list saldo a favor
 * POST   /customers/{id}/credit-balances/{cbId}/apply → apply credit to sale
 * GET    /customers/{id}/account-balance           → current ARS+USD balance
 * GET    /sellers/me/cash-owed                     → Seller's cash pending by destination
 *
 * RLS NOTE: All queries are automatically scoped by the Postgres RLS policies
 * configured in migration 2026_05_08_000005_enable_payments_rls. Eloquent does
 * not need explicit scope guards here — the middleware sets the GUCs.
 */
class PaymentController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /payments
    // -------------------------------------------------------------------------

    /**
     * Cursor-paginated list of payments.
     * Filters: sale_id, customer_id, date_from, date_to, reversed
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()
            ->with(['paymentMethod', 'recorder', 'sale'])
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at');

        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->input('sale_id'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->input('date_to'));
        }

        // Default: exclude reversed payments unless explicitly requested
        if (! $request->boolean('include_reversed', false)) {
            $query->active();
        }

        $payments = $query->cursorPaginate(20);

        return response()->json($payments);
    }

    // -------------------------------------------------------------------------
    // POST /payments
    // -------------------------------------------------------------------------

    /**
     * Register a new payment against a sale.
     * Wrapped in idempotency middleware (Idempotency-Key header).
     */
    public function store(
        RegisterPaymentRequest $request,
        RegisterPaymentAction $action,
    ): JsonResponse {
        $payment = $action->execute(
            payload: $request->toActionPayload(),
            actor: $request->user(),
        );

        return response()->json([
            'id'               => $payment->id,
            'sale_id'          => $payment->sale_id,
            'customer_id'      => $payment->customer_id,
            'amount'           => (string) $payment->amount->getAmount(),
            'currency'         => $payment->amount_currency,
            'payment_method'   => $payment->paymentMethod->name,
            'cash_destination' => $payment->cash_destination,
            'payment_date'     => $payment->payment_date->toDateString(),
            'is_advance'       => $payment->is_advance,
            'recorded_by'      => $payment->recorded_by,
        ], 201);
    }

    // -------------------------------------------------------------------------
    // DELETE /payments/{id}
    // -------------------------------------------------------------------------

    /**
     * Reverse a payment — Director only (§7.6).
     */
    public function destroy(
        ReversePaymentRequest $request,
        Payment $payment,
        ReversePaymentAction $action,
    ): Response {
        $action->execute(
            payment: $payment,
            actor: $request->user(),
            reason: $request->input('reason'),
        );

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // GET /customers/{id}/credit-balances
    // -------------------------------------------------------------------------

    /**
     * List saldo a favor for a customer.
     * Optionally filter by applied/unapplied.
     */
    public function creditBalancesIndex(
        Request $request,
        Customer $customer,
    ): JsonResponse {
        $query = CustomerCreditBalance::where('customer_id', $customer->id)
            ->orderByDesc('created_at');

        if ($request->boolean('unapplied_only', false)) {
            $query->unapplied();
        }

        if ($request->filled('currency')) {
            $query->inCurrency($request->input('currency'));
        }

        return response()->json($query->paginate(20));
    }

    // -------------------------------------------------------------------------
    // POST /customers/{id}/credit-balances/{cbId}/apply
    // -------------------------------------------------------------------------

    /**
     * Apply a credit balance to a sale — Director only.
     */
    public function applyCreditBalance(
        ApplyCreditBalanceRequest $request,
        Customer $customer,
        CustomerCreditBalance $creditBalance,
        ApplyCreditBalanceAction $action,
    ): JsonResponse {
        $sale = \App\Models\Sale::findOrFail($request->input('sale_id'));

        $updated = $action->execute(
            creditBalance: $creditBalance,
            sale: $sale,
            amount: $request->toMoney(),
            actor: $request->user(),
        );

        return response()->json([
            'id'                 => $updated->id,
            'customer_id'        => $updated->customer_id,
            'amount'             => (string) $updated->amount->getAmount(),
            'currency'           => $updated->amount_currency,
            'applied_to_sale_id' => $updated->applied_to_sale_id,
            'applied_at'         => $updated->applied_at?->toIso8601String(),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /customers/{id}/account-balance
    // -------------------------------------------------------------------------

    /**
     * Current ARS+USD account balance for a customer.
     */
    public function accountBalance(Customer $customer): JsonResponse
    {
        $balance = CustomerAccountBalance::where('customer_id', $customer->id)->first();

        return response()->json([
            'customer_id' => $customer->id,
            'balance_ars' => $balance?->balance_ars ?? '0.0000',
            'balance_usd' => $balance?->balance_usd ?? '0.0000',
            'updated_at'  => $balance?->updated_at?->toIso8601String(),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /sellers/me/cash-owed
    // -------------------------------------------------------------------------

    /**
     * Summary of cash payments the authenticated Seller collected, grouped by
     * destination (Distribuidor X / Dermacells), excluding reversed payments.
     *
     * Returns the amounts owed to each destination so the Seller knows how
     * much cash to render to whom (§7.2).
     */
    public function cashOwed(Request $request, CreditBalanceService $creditService): JsonResponse
    {
        $user = $request->user();

        // Directors see globally; Sellers see only their own
        $query = Payment::query()
            ->active()
            ->whereNotNull('cash_destination')
            ->where('amount_currency', 'ARS') // Cash is typically ARS but both handled
            ->selectRaw('cash_destination, cash_destination_dist_id, amount_currency, SUM(amount_amount) as total')
            ->groupBy('cash_destination', 'cash_destination_dist_id', 'amount_currency');

        if ($user->role === UserRole::Seller) {
            // Seller sees only payments on their own sales
            $query->whereIn('sale_id', function ($q): void {
                $q->select('id')->from('sales')->where('seller_id', request()->user()->id);
            });
        }

        $rows = $query->get();

        $summary = [];
        foreach ($rows as $row) {
            $destination = $row->cash_destination === 'distributor'
                ? 'distributor:' . $row->cash_destination_dist_id
                : 'dermacells';

            $summary[$destination][$row->amount_currency] = $row->total;
        }

        return response()->json(['cash_owed' => $summary]);
    }
}
