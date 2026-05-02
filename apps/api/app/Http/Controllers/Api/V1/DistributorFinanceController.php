<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\DistributorFinance\Services\CommissionAssignmentService;
use App\Enums\SettlementStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Jobs\DistributorFinance\RecalculateDistributorAccountJob;
use App\Models\DistributorAccount;
use App\Models\DistributorCommissionPayment;
use App\Models\DistributorPreferredCost;
use App\Models\DistributorSettlement;
use App\Models\Product;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * DistributorFinanceController — Phase 8 / §9
 *
 * Endpoints:
 *
 * Distributor-facing (self):
 *   GET    /distributors/me/account
 *   GET    /distributors/me/settlements
 *   POST   /distributors/me/settlements
 *   GET    /distributors/me/commission-config
 *   PUT    /distributors/me/commission-config/{sellerId}/{zoneId}
 *   GET    /distributors/me/commission-payments
 *   GET    /distributors/me/preferred-costs
 *
 * Shared (Distributor PATCH own; Director PATCH any):
 *   PATCH  /distributors/settlements/{id}/confirm
 *   PATCH  /distributors/commission-payments/{id}/pay
 *
 * Director-only:
 *   GET    /admin/distributors/{id}/preferred-costs
 *   PUT    /admin/distributors/{id}/preferred-costs/{productId}
 *
 * Authorization enforced inline (RLS provides the database-level guard;
 * application checks provide fast 403 before DB round-trip).
 */
class DistributorFinanceController extends Controller
{
    public function __construct(
        private readonly CommissionAssignmentService $commissionService,
    ) {}

    // =========================================================================
    // Distributor Account
    // =========================================================================

    /**
     * GET /distributors/me/account
     *
     * Returns the authenticated Distributor's account balance and gross margin.
     */
    public function myAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->requireRole($user, [UserRole::Distributor, UserRole::Director]);

        $account = DistributorAccount::where('distributor_id', $user->id)->first();

        if ($account === null) {
            return response()->json([
                'data' => null,
                'meta' => ['message' => 'Account not yet initialised. Create a settlement or wait for first sale.'],
            ]);
        }

        return response()->json([
            'data' => [
                'distributor_id'       => $account->distributor_id,
                'balance_ars'          => $account->balance_ars,
                'balance_usd'          => $account->balance_usd,
                'gross_margin_ars'     => $account->gross_margin_ars,
                'gross_margin_usd'     => $account->gross_margin_usd,
                'last_recalculated_at' => $account->last_recalculated_at?->toIso8601String(),
            ],
        ]);
    }

    // =========================================================================
    // Settlements (Rendiciones)
    // =========================================================================

    /**
     * GET /distributors/me/settlements
     *
     * Lists own settlements with status and pagination.
     */
    public function mySettlements(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Distributor, UserRole::Director]);

        $settlements = DistributorSettlement::where('distributor_id', $user->id)
            ->orderByDesc('submitted_at')
            ->paginate(25);

        return response()->json([
            'data' => $settlements->map(fn (DistributorSettlement $s) => $this->formatSettlement($s)),
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'last_page'    => $settlements->lastPage(),
                'total'        => $settlements->total(),
            ],
        ]);
    }

    /**
     * POST /distributors/me/settlements
     *
     * Distributor initiates a rendición (pending status).
     */
    public function submitSettlement(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Distributor]);

        $validated = $request->validate([
            'amount'           => ['required', 'numeric', 'gt:0'],
            'currency'         => ['required', 'string', Rule::in(['ARS', 'USD'])],
            'payment_method_id' => ['nullable', 'uuid'],
            'reference'        => ['nullable', 'string', 'max:500'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        $settlement = DB::transaction(function () use ($user, $validated): DistributorSettlement {
            return DistributorSettlement::create([
                'distributor_id'    => $user->id,
                'amount_amount'     => number_format((float) $validated['amount'], 4, '.', ''),
                'amount_currency'   => $validated['currency'],
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'reference'         => $validated['reference'] ?? null,
                'notes'             => $validated['notes'] ?? null,
                'status'            => SettlementStatus::Pending,
                'submitted_at'      => now(),
            ]);
        });

        return response()->json([
            'data' => $this->formatSettlement($settlement),
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH /distributors/settlements/{id}/confirm
     *
     * Director confirms (or rejects) a pending settlement.
     * On confirm: triggers account recalculation for the Distributor.
     */
    public function confirmSettlement(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Director]);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'reject'])],
            'notes'  => ['nullable', 'string', 'max:2000'],
        ]);

        $settlement = DistributorSettlement::findOrFail($id);

        if (! $settlement->isPending()) {
            return response()->json([
                'error' => [
                    'code'    => 'SETTLEMENT_NOT_PENDING',
                    'message' => 'Only pending settlements can be confirmed or rejected.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        DB::transaction(function () use ($settlement, $user, $validated): void {
            $newStatus = $validated['action'] === 'confirm'
                ? SettlementStatus::Confirmed
                : SettlementStatus::Rejected;

            $settlement->status       = $newStatus;
            $settlement->confirmed_by = $user->id;
            $settlement->confirmed_at = now();
            $settlement->notes        = $validated['notes'] ?? $settlement->notes;
            $settlement->save();
        });

        // Trigger async account recalculation
        RecalculateDistributorAccountJob::dispatch($settlement->distributor_id);

        return response()->json([
            'data' => $this->formatSettlement($settlement->fresh()),
        ]);
    }

    // =========================================================================
    // Commission Config
    // =========================================================================

    /**
     * GET /distributors/me/commission-config
     *
     * Returns all active commission percentages the Distributor has set for
     * each (seller, zone) in their zone(s).
     */
    public function myCommissionConfig(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Distributor, UserRole::Director]);

        // Return all versioned rows (client can filter for most recent effective_from)
        $configs = \App\Models\SellerCommissionConfig::where('distributor_id', $user->id)
            ->with(['seller:id,full_name,email,role', 'zone:id,name'])
            ->orderByDesc('effective_from')
            ->get();

        return response()->json([
            'data' => $configs->map(fn (\App\Models\SellerCommissionConfig $c) => [
                'id'             => $c->id,
                'seller_id'      => $c->seller_id,
                'seller_name'    => $c->seller?->full_name,
                'zone_id'        => $c->zone_id,
                'zone_name'      => $c->zone?->name,
                'commission_pct' => $c->commission_pct,
                'effective_from' => $c->effective_from?->toDateString(),
                'set_by'         => $c->set_by,
            ]),
        ]);
    }

    /**
     * PUT /distributors/me/commission-config/{sellerId}/{zoneId}
     *
     * Distributor sets or updates the commission % for a Seller in a Zone.
     */
    public function setCommissionConfig(Request $request, string $sellerId, string $zoneId): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Distributor, UserRole::Director]);

        $validated = $request->validate([
            'commission_pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'effective_from' => ['nullable', 'date', 'date_format:Y-m-d'],
        ]);

        $seller = User::findOrFail($sellerId);
        $zone   = Zone::findOrFail($zoneId);

        // Distributor can only set for their own zone
        if ($user->role === UserRole::Distributor && $zone->distributor_id !== $user->id) {
            return response()->json([
                'error' => ['code' => 'ZONE_NOT_OWNED', 'message' => 'You can only set commissions for your own zone.'],
            ], Response::HTTP_FORBIDDEN);
        }

        $effectiveFrom = isset($validated['effective_from'])
            ? Carbon::parse($validated['effective_from'])
            : null;

        $config = $this->commissionService->setCommissionPct(
            distributor: $user->role === UserRole::Director
                ? User::findOrFail($zone->distributor_id ?? $user->id)
                : $user,
            seller: $seller,
            zone: $zone,
            pct: (float) $validated['commission_pct'],
            setBy: $user->id,
            effectiveFrom: $effectiveFrom,
        );

        return response()->json([
            'data' => [
                'id'             => $config->id,
                'seller_id'      => $config->seller_id,
                'zone_id'        => $config->zone_id,
                'commission_pct' => $config->commission_pct,
                'effective_from' => $config->effective_from?->toDateString(),
            ],
        ]);
    }

    // =========================================================================
    // Commission Payments
    // =========================================================================

    /**
     * GET /distributors/me/commission-payments
     *
     * Returns pending and recently paid commission payments for the Distributor.
     */
    public function myCommissionPayments(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Distributor, UserRole::Director]);

        $payments = DistributorCommissionPayment::where('distributor_id', $user->id)
            ->with(['seller:id,full_name,email', 'zone:id,name'])
            ->orderByDesc('period_month')
            ->paginate(50);

        return response()->json([
            'data' => $payments->map(fn (DistributorCommissionPayment $p) => $this->formatCommissionPayment($p)),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'total'        => $payments->total(),
            ],
        ]);
    }

    /**
     * PATCH /distributors/commission-payments/{id}/pay
     *
     * Distributor records that they paid a commission to a Seller.
     */
    public function recordCommissionPaid(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Distributor, UserRole::Director]);

        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = DistributorCommissionPayment::findOrFail($id);

        // Distributor can only pay their own commission payments
        if ($user->role === UserRole::Distributor && $payment->distributor_id !== $user->id) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You can only record payments for your own commissions.'],
            ], Response::HTTP_FORBIDDEN);
        }

        if ($payment->paid) {
            return response()->json([
                'error' => ['code' => 'ALREADY_PAID', 'message' => 'This commission payment is already recorded as paid.'],
            ], Response::HTTP_CONFLICT);
        }

        DB::transaction(function () use ($payment, $user, $validated): void {
            $payment->paid              = true;
            $payment->paid_at           = now();
            $payment->paid_by           = $user->id;
            $payment->payment_reference = $validated['payment_reference'] ?? null;
            $payment->save();
        });

        // Trigger account recalculation to reflect commission paid
        RecalculateDistributorAccountJob::dispatch($payment->distributor_id);

        return response()->json([
            'data' => $this->formatCommissionPayment($payment->fresh()),
        ]);
    }

    // =========================================================================
    // Preferred Costs — Distributor self-view
    // =========================================================================

    /**
     * GET /distributors/me/preferred-costs
     *
     * Distributor sees their own preferred cost configuration per product.
     */
    public function myPreferredCosts(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireRole($user, [UserRole::Distributor]);

        $costs = DistributorPreferredCost::where('distributor_id', $user->id)
            ->with('product:id,name,base_price_amount,base_price_currency')
            ->get();

        return response()->json([
            'data' => $costs->map(fn (DistributorPreferredCost $c) => $this->formatPreferredCost($c)),
        ]);
    }

    // =========================================================================
    // Preferred Costs — Director admin
    // =========================================================================

    /**
     * GET /admin/distributors/{id}/preferred-costs
     *
     * Director views preferred cost configuration for any Distributor.
     */
    public function adminPreferredCosts(Request $request, string $distributorId): JsonResponse
    {
        $this->requireRole($request->user(), [UserRole::Director]);

        $distributor = User::findOrFail($distributorId);

        $costs = DistributorPreferredCost::where('distributor_id', $distributor->id)
            ->with('product:id,name,base_price_amount,base_price_currency')
            ->get();

        return response()->json([
            'data' => $costs->map(fn (DistributorPreferredCost $c) => $this->formatPreferredCost($c)),
        ]);
    }

    /**
     * PUT /admin/distributors/{id}/preferred-costs/{productId}
     *
     * Director sets or updates the preferred cost for a (Distributor, Product) pair.
     */
    public function adminSetPreferredCost(Request $request, string $distributorId, string $productId): JsonResponse
    {
        $this->requireRole($request->user(), [UserRole::Director]);

        $validated = $request->validate([
            'modality' => ['required', Rule::in(['fixed_price', 'discount_pct'])],
            'value'    => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', Rule::in(['ARS', 'USD'])],
        ]);

        // Validate range for discount_pct
        if ($validated['modality'] === 'discount_pct' && $validated['value'] > 1) {
            return response()->json([
                'error' => [
                    'code'    => 'INVALID_DISCOUNT_PCT',
                    'message' => 'Discount percentage must be between 0 and 1 (e.g. 0.20 for 20%).',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $distributor = User::findOrFail($distributorId);
        $product     = Product::findOrFail($productId);

        $cost = DB::transaction(function () use ($distributor, $product, $validated, $request): DistributorPreferredCost {
            $existing = DistributorPreferredCost::where('distributor_id', $distributor->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            $data = [
                'distributor_id' => $distributor->id,
                'product_id'     => $product->id,
                'modality'       => $validated['modality'],
                'value'          => number_format((float) $validated['value'], 4, '.', ''),
                'currency'       => $validated['currency'] ?? 'USD',
                'updated_by'     => $request->user()->id,
                'updated_at'     => now(),
            ];

            if ($existing !== null) {
                $existing->fill($data)->save();
                return $existing->fresh();
            }

            return DistributorPreferredCost::create($data);
        });

        return response()->json([
            'data' => $this->formatPreferredCost($cost),
        ]);
    }

    // =========================================================================
    // Private formatters
    // =========================================================================

    /** @return array<string, mixed> */
    private function formatSettlement(DistributorSettlement $s): array
    {
        return [
            'id'                => $s->id,
            'distributor_id'    => $s->distributor_id,
            'amount'            => $s->amount_amount,
            'currency'          => $s->amount_currency,
            'payment_method_id' => $s->payment_method_id,
            'reference'         => $s->reference,
            'status'            => $s->status->value,
            'status_label'      => $s->status->label(),
            'submitted_at'      => $s->submitted_at?->toIso8601String(),
            'confirmed_by'      => $s->confirmed_by,
            'confirmed_at'      => $s->confirmed_at?->toIso8601String(),
            'notes'             => $s->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function formatCommissionPayment(DistributorCommissionPayment $p): array
    {
        return [
            'id'                         => $p->id,
            'distributor_id'             => $p->distributor_id,
            'seller_id'                  => $p->seller_id,
            'seller_name'                => $p->seller?->full_name,
            'zone_id'                    => $p->zone_id,
            'zone_name'                  => $p->zone?->name,
            'period_month'               => $p->period_month?->toDateString(),
            'base_amount'                => $p->base_amount_amount,
            'base_currency'              => $p->base_amount_currency,
            'commission_pct'             => $p->commission_pct,
            'commission_amount'          => $p->commission_amount_amount,
            'commission_currency'        => $p->commission_amount_currency,
            'paid'                       => $p->paid,
            'paid_at'                    => $p->paid_at?->toIso8601String(),
            'paid_by'                    => $p->paid_by,
            'payment_reference'          => $p->payment_reference,
        ];
    }

    /** @return array<string, mixed> */
    private function formatPreferredCost(DistributorPreferredCost $c): array
    {
        return [
            'id'             => $c->id,
            'distributor_id' => $c->distributor_id,
            'product_id'     => $c->product_id,
            'product_name'   => $c->product?->name,
            'modality'       => $c->modality->value,
            'modality_label' => $c->modality->label(),
            'value'          => $c->value,
            'currency'       => $c->currency,
            'updated_by'     => $c->updated_by,
            'updated_at'     => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Inline role gate — 403 if user's role is not in the allowed list.
     *
     * @param User              $user
     * @param array<UserRole>   $allowed
     */
    private function requireRole(User $user, array $allowed): void
    {
        if (! in_array($user->role, $allowed, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Insufficient role for this endpoint.');
        }
    }
}
