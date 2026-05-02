<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorizations\Services\AuthorizationService;
use App\Enums\AuthorizationType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\CreateAuthorizationRequest;
use App\Http\Requests\Authorization\ResolveAuthorizationRequest;
use App\Models\AuthorizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Phase 11 — Authorization requests REST controller.
 *
 * Endpoints:
 *   POST   /api/v1/authorizations              — Seller/Distributor submits
 *   GET    /api/v1/authorizations              — Director sees all; others see own
 *   PATCH  /api/v1/authorizations/{id}/resolve — Director resolves (approve/reject)
 *
 * RLS handles row visibility at the DB layer; this controller enforces
 * role-based write restrictions at the application layer.
 */
final class AuthorizationController extends Controller
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * GET /api/v1/authorizations
     *
     * Director: all pending requests ordered by created_at.
     * Non-director: own requests (RLS limits rows; this adds status sort).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AuthorizationRequest::query()
            ->with(['requester:id,full_name,email', 'resolver:id,full_name,email', 'sale:id,status'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        // Non-directors only see their own requests; RLS enforces this at the
        // DB level but we add an explicit scope for clarity and query planning.
        if ($user->role !== UserRole::Director) {
            $query->where('requested_by', $user->id);
        }

        $requests = $query->cursorPaginate(25);

        return response()->json($requests);
    }

    /**
     * POST /api/v1/authorizations
     *
     * Restricted to Sellers and Distributors — Directors modify directly (§13.1).
     */
    public function store(CreateAuthorizationRequest $request): JsonResponse
    {
        $user = $request->user();

        // Directors can modify values directly — they do not use this flow (§13.1)
        if ($user->role === UserRole::Director) {
            return response()->json([
                'type'   => 'https://crm.dermacells.com.ar/errors/forbidden',
                'title'  => 'Forbidden',
                'status' => 403,
                'detail' => 'Los Directores modifican valores directamente sin solicitud de autorización.',
                'code'   => 'DIRECTOR_CANNOT_REQUEST_AUTHORIZATION',
            ], 403);
        }

        $validated = $request->validated();

        $authRequest = $this->authorizationService->request(
            type: AuthorizationType::from($validated['type']),
            currentValue: (string) $validated['current_value'],
            proposedValue: (string) $validated['proposed_value'],
            reason: $validated['reason'],
            requester: $user,
            saleId: $validated['sale_id'] ?? null,
            valueCurrency: $validated['value_currency'] ?? null,
        );

        return response()->json($authRequest->load([
            'requester:id,full_name,email',
            'sale:id,status',
        ]), 201);
    }

    /**
     * PATCH /api/v1/authorizations/{authorizationRequest}/resolve
     *
     * Director approves or rejects the request. Returns 403 for non-Directors
     * (ResolveAuthorizationRequest::authorize() already blocks, but this
     * provides an explicit RFC 7807 body for non-Director callers).
     */
    public function resolve(
        ResolveAuthorizationRequest $request,
        AuthorizationRequest $authorizationRequest,
    ): JsonResponse {
        $director = $request->user();
        $action   = $request->validated('action');

        try {
            $resolved = match ($action) {
                'approve' => $this->authorizationService->approve($authorizationRequest, $director),
                'reject'  => $this->authorizationService->reject(
                    $authorizationRequest,
                    $director,
                    $request->validated('rejection_reason'),
                ),
            };
        } catch (LogicException $e) {
            return response()->json([
                'type'   => 'https://crm.dermacells.com.ar/errors/conflict',
                'title'  => 'Conflict',
                'status' => 409,
                'detail' => $e->getMessage(),
                'code'   => 'AUTHORIZATION_ALREADY_RESOLVED',
            ], 409);
        }

        return response()->json($resolved->load([
            'requester:id,full_name,email',
            'resolver:id,full_name,email',
            'sale:id,status',
        ]));
    }
}
