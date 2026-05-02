<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\CancelSaleAction;
use App\Actions\Sales\ConfirmPartialReturnAction;
use App\Actions\Sales\ConfirmSaleAction;
use App\Actions\Sales\CreateDraftSaleAction;
use App\Actions\Sales\DeliverSaleAction;
use App\Actions\Sales\InitiatePartialReturnAction;
use App\Data\Sales\SaleData;
use App\Exceptions\InvoiceNcRequiredException;
use App\Exceptions\InvalidSaleTransitionException;
use App\Exceptions\OperationBlockedByPendingAuthorizationException;
use App\Exceptions\StockInsufficientException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CancelSaleRequest;
use App\Http\Requests\Sales\ConfirmSaleRequest;
use App\Http\Requests\Sales\CreateDraftSaleRequest;
use App\Http\Requests\Sales\InitiatePartialReturnRequest;
use App\Models\PartialReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sale API controller — /api/v1/sales.
 *
 * All responses follow RFC 7807 problem+json for errors.
 * Cursor pagination is used for list endpoints to support large datasets
 * without OFFSET performance degradation (Phase 0 spec).
 *
 * Endpoint overview:
 *   GET    /sales                         — paginated sale list (cursor)
 *   POST   /sales                         — create draft (idempotent)
 *   GET    /sales/{id}                    — show single sale
 *   PATCH  /sales/{id}/confirm            — confirm (reserve stock)
 *   PATCH  /sales/{id}/deliver            — deliver (commit stock)
 *   DELETE /sales/{id}                    — cancel
 *   POST   /sales/{id}/returns            — initiate partial return
 *   PATCH  /sales/{id}/returns/{rid}/confirm — Director confirms partial return
 */
class SaleController extends Controller
{
    public function __construct(
        private readonly CreateDraftSaleAction $createDraft,
        private readonly ConfirmSaleAction $confirm,
        private readonly DeliverSaleAction $deliver,
        private readonly CancelSaleAction $cancel,
        private readonly InitiatePartialReturnAction $initiateReturn,
        private readonly ConfirmPartialReturnAction $confirmReturn,
    ) {}

    // -------------------------------------------------------------------------
    // GET /sales
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $sales = Sale::with(['customer', 'items', 'exchangeRate'])
            ->cursorPaginate(perPage: 25, cursor: $request->query('cursor'));

        return response()->json([
            'data'       => $sales->items(),
            'meta'       => [
                'next_cursor' => $sales->nextCursor()?->encode(),
                'prev_cursor' => $sales->previousCursor()?->encode(),
                'per_page'    => $sales->perPage(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /sales (idempotent middleware applied in routes/api.php)
    // -------------------------------------------------------------------------

    public function store(CreateDraftSaleRequest $request): JsonResponse
    {
        $payload = $request->toPayload();

        ['sale' => $sale, 'stockWarnings' => $warnings]
            = $this->createDraft->execute($payload, $request->user());

        $response = [
            'data' => SaleData::fromModel($sale),
        ];

        if (! empty($warnings)) {
            $response['meta']['stock_warnings'] = $warnings;
        }

        return response()->json($response, 201);
    }

    // -------------------------------------------------------------------------
    // GET /sales/{id}
    // -------------------------------------------------------------------------

    public function show(Sale $sale): JsonResponse
    {
        $sale->load(['customer', 'items.product', 'statusHistory', 'partialReturns', 'exchangeRate']);

        return response()->json(['data' => SaleData::fromModel($sale)]);
    }

    // -------------------------------------------------------------------------
    // PATCH /sales/{id}/confirm
    // -------------------------------------------------------------------------

    public function confirm(ConfirmSaleRequest $request, Sale $sale): JsonResponse
    {
        try {
            $sale = $this->confirm->execute($sale, $request->user(), $request->input('note'));
        } catch (OperationBlockedByPendingAuthorizationException $e) {
            return $this->problem(409, 'PENDING_AUTHORIZATION_EXISTS', $e->getMessage(), [
                'sale_id' => $e->saleId,
            ]);
        } catch (StockInsufficientException $e) {
            return $this->problem(422, $e->getApiCode(), $e->getMessage(), [
                'product_id' => $e->getProductId(),
                'requested'  => $e->getRequested(),
                'available'  => $e->getAvailable(),
            ]);
        } catch (InvalidSaleTransitionException $e) {
            return $this->problem($e->getStatusCode(), 'INVALID_TRANSITION', $e->getMessage());
        }

        return response()->json(['data' => SaleData::fromModel($sale->load('items'))]);
    }

    // -------------------------------------------------------------------------
    // PATCH /sales/{id}/deliver
    // -------------------------------------------------------------------------

    public function deliver(Request $request, Sale $sale): JsonResponse
    {
        try {
            $sale = $this->deliver->execute($sale, $request->user(), $request->input('note'));
        } catch (InvalidSaleTransitionException $e) {
            return $this->problem($e->getStatusCode(), 'INVALID_TRANSITION', $e->getMessage());
        }

        return response()->json(['data' => SaleData::fromModel($sale->load('items'))]);
    }

    // -------------------------------------------------------------------------
    // DELETE /sales/{id}
    // -------------------------------------------------------------------------

    public function destroy(CancelSaleRequest $request, Sale $sale): JsonResponse
    {
        try {
            $this->cancel->execute($sale, $request->user(), $request->input('reason'));
        } catch (InvoiceNcRequiredException $e) {
            return $this->problem(
                409,
                $e->getApiCode(),
                $e->getMessage(),
            );
        } catch (InvalidSaleTransitionException $e) {
            return $this->problem($e->getStatusCode(), 'INVALID_TRANSITION', $e->getMessage());
        }

        return response()->json(null, 204);
    }

    // -------------------------------------------------------------------------
    // POST /sales/{id}/returns
    // -------------------------------------------------------------------------

    public function initiateReturn(InitiatePartialReturnRequest $request, Sale $sale): JsonResponse
    {
        $saleItem = SaleItem::findOrFail($request->validated('sale_item_id'));

        $return = $this->initiateReturn->execute(
            $sale,
            $saleItem,
            $request->user(),
            $request->validated(),
        );

        return response()->json(['data' => $return], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /sales/{id}/returns/{rid}/confirm
    // -------------------------------------------------------------------------

    public function confirmReturn(Request $request, Sale $sale, PartialReturn $return): JsonResponse
    {
        // Ensure the return belongs to this sale
        abort_unless($return->sale_id === $sale->id, 404);

        try {
            $return = $this->confirmReturn->execute($return, $request->user());
        } catch (InvoiceNcRequiredException $e) {
            // Return was transitioned to 'awaiting_credit_note' but we still
            // surface the 409 so Phase 6 can catch it and start the NC flow.
            return $this->problem(
                409,
                $e->getApiCode(),
                $e->getMessage(),
            );
        }

        return response()->json(['data' => $return->refresh()]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * RFC 7807 problem+json response.
     *
     * @param array<string,mixed> $extra  Additional meta fields merged into the body
     */
    private function problem(int $status, string $code, string $detail, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'type'   => "https://crm.dermacells.com/problems/{$code}",
            'title'  => $code,
            'status' => $status,
            'detail' => $detail,
            'code'   => $code,
        ], $extra), $status)->header('Content-Type', 'application/problem+json');
    }
}
