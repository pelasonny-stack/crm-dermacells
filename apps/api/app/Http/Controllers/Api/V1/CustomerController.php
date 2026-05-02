<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customer\CreateCustomerAction;
use App\Actions\Customer\CreateScheduledActionAction;
use App\Actions\Customer\DeactivateCustomerAction;
use App\Data\CustomerData;
use App\Data\ScheduledActionData;
use App\Enums\UserRole;
use App\Exceptions\CustomerHasPendingObligationsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\CreateScheduledActionRequest;
use App\Http\Requests\Customer\DeactivateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CustomerController — REST CRUD for the customers domain (§3).
 *
 * SECURITY MODEL
 * ==============
 * RLS in Postgres enforces visibility at the database layer:
 *   - Sellers   see only their own assigned customers.
 *   - Distributors see customers in their zone(s), excluding category D.
 *   - Directors see all customers including category D.
 *
 * The SetPostgresRlsContext middleware (applied globally) seeds the GUCs
 * before every query, so Eloquent queries automatically respect the policy.
 *
 * PERMISSIONS META BLOCK
 * =======================
 * Every resource response includes a `meta.permissions` object per Phase 0
 * spec. This lets the client render action buttons without a second round-trip.
 *
 * REFERENCE PRICE PROTECTION
 * ==========================
 * Per §3.2, only Directors can edit reference_price. The update() method
 * strips those fields from the payload for non-director users before delegating
 * to the action — an extra defense-in-depth layer on top of Form Request rules.
 *
 * HTTP 409 mapping
 * ================
 * CustomerHasPendingObligationsException is caught here and serialised as
 * RFC 7807 problem+json with HTTP 409 (Conflict).
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CreateCustomerAction $createCustomer,
        private readonly DeactivateCustomerAction $deactivateCustomer,
        private readonly CreateScheduledActionAction $createScheduledAction,
    ) {}

    /**
     * GET /api/v1/customers
     *
     * Returns a paginated list of customers visible to the authenticated user.
     * RLS enforces the scope; this method only adds optional client-side filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()
            ->with(['category', 'zone', 'assignedSeller', 'defaultPaymentTerm'])
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->when($request->filled('zone_id'), fn ($q) => $q->inZone($request->string('zone_id')->toString()))
            ->when($request->filled('assigned_seller_id'), fn ($q) => $q->assignedTo($request->string('assigned_seller_id')->toString()))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($inner) use ($request): void {
                $term = '%' . $request->string('search')->toString() . '%';
                $inner->where('first_name', 'ilike', $term)
                      ->orWhere('last_name', 'ilike', $term)
                      ->orWhere('cuit', 'like', $term . '%');
            }))
            ->orderBy('last_name')
            ->orderBy('first_name');

        $paginated = $query->paginate($request->integer('per_page', 25));

        $user = $request->user();

        return response()->json([
            'data' => $paginated->getCollection()->map(
                fn (Customer $c) => CustomerData::fromModel($c, $this->permissionsFor($c, $user))->toArray()
            ),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/customers/{customer}
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        // Defense-in-depth: SubstituteBindings resolves the model before
        // SetPostgresRlsContext sets the GUC, so RLS cannot block route model
        // binding for category-D customers. Enforce the same protection here.
        $user = $request->user();
        if ($user?->role !== UserRole::Director) {
            $customer->loadMissing('category');
            if ($customer->category?->director_only) {
                abort(404);
            }
        }

        $customer->load(['category', 'zone', 'assignedSeller', 'defaultPaymentTerm', 'billingEntities', 'contacts', 'scheduledActions']);

        return response()->json([
            'data' => CustomerData::fromModel($customer, $this->permissionsFor($customer, $request->user()))->toArray(),
        ]);
    }

    /**
     * POST /api/v1/customers
     */
    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $customer = $this->createCustomer->execute(
            data:            $validated,
            billingEntities: $validated['billing_entities'] ?? [],
            createdBy:       $request->user(),
        );

        return response()->json([
            'data' => CustomerData::fromModel($customer, $this->permissionsFor($customer, $request->user()))->toArray(),
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/customers/{customer}
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $data = $request->validated();

        // Strip reference_price fields for non-directors — §3.2
        if ($request->user()?->role !== UserRole::Director) {
            unset($data['reference_price_amount'], $data['reference_price_currency'], $data['reference_price_unit_amount']);
        }

        $customer->update($data);
        $customer->load(['category', 'zone', 'assignedSeller', 'defaultPaymentTerm']);

        return response()->json([
            'data' => CustomerData::fromModel($customer, $this->permissionsFor($customer, $request->user()))->toArray(),
        ]);
    }

    /**
     * DELETE /api/v1/customers/{customer}
     *
     * Logical deactivation — never hard-deletes. Returns HTTP 204 on success,
     * HTTP 409 if blocking obligations exist (and user is not forcing).
     */
    public function destroy(DeactivateCustomerRequest $request, Customer $customer): JsonResponse|Response
    {
        try {
            $this->deactivateCustomer->execute(
                customer:      $customer,
                deactivatedBy: $request->user(),
                reason:        $request->string('reason')->toString(),
                force:         $request->isForced(),
            );
        } catch (CustomerHasPendingObligationsException $e) {
            return response()->json([
                'type'   => 'https://dermacells.com/errors/customer-has-pending-obligations',
                'title'  => 'Customer Has Pending Obligations',
                'status' => Response::HTTP_CONFLICT,
                'detail' => $e->getMessage(),
                'code'   => 'CUSTOMER_HAS_PENDING_OBLIGATIONS',
                'meta'   => ['reasons' => $e->reasons()],
            ], Response::HTTP_CONFLICT)->header('Content-Type', 'application/problem+json');
        }

        return response()->noContent();
    }

    // =========================================================================
    // Nested resource: Scheduled Actions
    // =========================================================================

    /**
     * GET /api/v1/customers/{customer}/scheduled-actions
     */
    public function scheduledActionsIndex(Request $request, Customer $customer): JsonResponse
    {
        // Defense-in-depth: SubstituteBindings resolves the model before
        // SetPostgresRlsContext sets the GUC, so RLS cannot block route model
        // binding. Enforce visibility rules at the application layer.
        $user = $request->user();
        if ($user?->role !== UserRole::Director) {
            // Category-D customers are invisible to non-directors.
            $customer->loadMissing('category');
            if ($customer->category?->director_only) {
                abort(404);
            }

            // Sellers can only see customers assigned to them.
            if ($user->role === UserRole::Seller && $customer->assigned_seller_id !== $user->id) {
                abort(404);
            }
        }

        $actions = $customer->scheduledActions()
            ->orderBy('scheduled_date')
            ->get();

        return response()->json([
            'data' => $actions->map(fn ($a) => ScheduledActionData::fromModel($a)->toArray()),
        ]);
    }

    /**
     * POST /api/v1/customers/{customer}/scheduled-actions
     */
    public function scheduledActionsStore(CreateScheduledActionRequest $request, Customer $customer): JsonResponse
    {
        $action = $this->createScheduledAction->execute(
            customer:      $customer,
            createdBy:     $request->user(),
            scheduledDate: $request->string('scheduled_date')->toString(),
            note:          $request->string('note')->toString(),
        );

        return response()->json([
            'data' => ScheduledActionData::fromModel($action)->toArray(),
        ], Response::HTTP_CREATED);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build the permissions map for the meta block (Phase 0 spec).
     *
     * @return array<string, bool>
     */
    private function permissionsFor(Customer $customer, ?\Illuminate\Contracts\Auth\Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        /** @var \App\Models\User $user */
        $isDirector     = $user->role === UserRole::Director;
        $isOwnSeller    = $user->role === UserRole::Seller && $customer->assigned_seller_id === $user->id;
        $isDistributor  = $user->role === UserRole::Distributor;

        return [
            'can_edit'          => $isDirector || $isOwnSeller || $isDistributor,
            'can_edit_price'    => $isDirector,
            'can_deactivate'    => $isDirector || $isOwnSeller || $isDistributor,
            'can_force_deactivate' => $isDirector,
            'can_reassign'      => $isDirector || $isDistributor,
        ];
    }
}
