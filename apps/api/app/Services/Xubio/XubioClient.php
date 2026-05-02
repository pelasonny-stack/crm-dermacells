<?php

declare(strict_types=1);

namespace App\Services\Xubio;

use App\Models\CustomerBillingEntity;
use App\Models\XubioApiLog;
use App\Services\Xubio\Dto\CreditNotePayload;
use App\Services\Xubio\Dto\InvoicePayload;
use App\Services\Xubio\Dto\InvoiceResponse;
use App\Services\Xubio\Exceptions\XubioRejectedException;
use App\Services\Xubio\Exceptions\XubioTimeoutException;
use App\Services\Xubio\Exceptions\XubioUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * HTTP client for Xubio API 1.1.
 *
 * Endpoints:
 *   POST /FacturaVenta            — emit invoice
 *   POST /NotaCreditoVenta        — emit credit note (with idComprobanteAsociado)
 *   GET  /FacturaVenta/{id}       — fetch invoice metadata
 *   GET  /FacturaVenta/{id}/pdf   — fetch PDF binary
 *   GET  /Cliente?cuit=...        — find cliente by CUIT
 *   POST /Cliente                 — create cliente
 *
 * MOCK MODE
 * =========
 * When XUBIO_CLIENT_ID is empty (local dev), emitInvoice() and
 * emitCreditNote() return a deterministic fake response WITHOUT hitting
 * the network. This lets the Filament panel and the API work end-to-end
 * without real Xubio credentials. See ::isMockMode().
 *
 * IDEMPOTENCY GOTCHA
 * ==================
 * Xubio (and AFIP) may grant a CAE then time out before the response
 * reaches us. NEVER retry a POST automatically — the caller (Job) must
 * transition to 'reconciling' and dispatch ReconcileXubioInvoiceJob to
 * search by externalRef before any retry.
 */
class XubioClient
{
    public function __construct(
        private readonly TokenManager $tokenManager,
    ) {
    }

    // ------------------------------------------------------------------
    // Mock mode (local dev without real Xubio credentials)
    // ------------------------------------------------------------------

    public function isMockMode(): bool
    {
        return (string) config('services.xubio.client_id') === '';
    }

    // ------------------------------------------------------------------
    // Invoice emission
    // ------------------------------------------------------------------

    /**
     * @throws XubioUnavailableException
     * @throws XubioTimeoutException
     * @throws XubioRejectedException
     */
    public function emitInvoice(InvoicePayload $payload): InvoiceResponse
    {
        if ($this->isMockMode()) {
            return $this->mockInvoiceResponse($payload);
        }

        $body = $this->postJson('/FacturaVenta', $payload->toArray());

        return new InvoiceResponse(
            xubioId:       (string) ($body['id'] ?? ''),
            invoiceNumber: (string) ($body['numero'] ?? $body['numeroComprobante'] ?? ''),
            cae:           (string) ($body['cae'] ?? ''),
            voucherType:   (string) ($body['tipoComprobante'] ?? $payload->voucherType),
            amountArs:     (string) ($body['total'] ?? $payload->amountArs),
            pdfUrl:        $body['pdfUrl'] ?? null,
            raw:           is_array($body) ? $body : [],
        );
    }

    /**
     * @throws XubioUnavailableException
     * @throws XubioTimeoutException
     * @throws XubioRejectedException
     */
    public function emitCreditNote(CreditNotePayload $payload): InvoiceResponse
    {
        if ($this->isMockMode()) {
            return $this->mockCreditNoteResponse($payload);
        }

        $body = $this->postJson('/NotaCreditoVenta', $payload->toArray());

        return new InvoiceResponse(
            xubioId:       (string) ($body['id'] ?? ''),
            invoiceNumber: (string) ($body['numero'] ?? $body['numeroComprobante'] ?? ''),
            cae:           (string) ($body['cae'] ?? ''),
            voucherType:   (string) ($body['tipoComprobante'] ?? $payload->voucherType),
            amountArs:     (string) ($body['total'] ?? $payload->amountArs),
            pdfUrl:        $body['pdfUrl'] ?? null,
            raw:           is_array($body) ? $body : [],
        );
    }

    // ------------------------------------------------------------------
    // Read paths
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     *
     * @throws XubioUnavailableException
     */
    public function getInvoice(string $xubioId): ?array
    {
        if ($this->isMockMode()) {
            return null;
        }

        try {
            $response = $this->request()->get('/FacturaVenta/' . $xubioId);
        } catch (ConnectionException $e) {
            throw new XubioUnavailableException('Xubio GET /FacturaVenta failed: ' . $e->getMessage(), 0, $e);
        }

        $this->logCall('GET', '/FacturaVenta/' . $xubioId, null, $response->json(), $response->status());

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new XubioUnavailableException(
                sprintf('Xubio GET /FacturaVenta/%s returned %d', $xubioId, $response->status())
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Search for an invoice by our external ref. Returns the Xubio body or null.
     *
     * @return array<string,mixed>|null
     */
    public function findInvoiceByExternalRef(string $externalRef): ?array
    {
        if ($this->isMockMode()) {
            return null;
        }

        try {
            $response = $this->request()->get('/FacturaVenta', ['externalRef' => $externalRef]);
        } catch (ConnectionException $e) {
            throw new XubioUnavailableException('Xubio GET /FacturaVenta search failed: ' . $e->getMessage(), 0, $e);
        }

        $this->logCall('GET', '/FacturaVenta?externalRef=' . $externalRef, null, $response->json(), $response->status());

        if ($response->failed()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        // Some endpoints return an array; pick first hit
        if (array_is_list($json) && isset($json[0])) {
            return $json[0];
        }

        return $json;
    }

    /**
     * Returns binary PDF content for the given invoice.
     *
     * @throws XubioUnavailableException
     */
    public function getInvoicePdf(string $xubioId): string
    {
        if ($this->isMockMode()) {
            return "%PDF-1.4\n% mock pdf for {$xubioId}\n";
        }

        try {
            $response = $this->request()->get('/FacturaVenta/' . $xubioId . '/pdf');
        } catch (ConnectionException $e) {
            throw new XubioUnavailableException('Xubio GET /FacturaVenta/{id}/pdf failed: ' . $e->getMessage(), 0, $e);
        }

        $this->logCall('GET', '/FacturaVenta/' . $xubioId . '/pdf', null, ['size' => strlen($response->body())], $response->status());

        if ($response->failed()) {
            throw new XubioUnavailableException(
                sprintf('Xubio GET PDF for %s returned %d', $xubioId, $response->status())
            );
        }

        return $response->body();
    }

    // ------------------------------------------------------------------
    // Cliente lookup / creation
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    public function findClienteByCuit(string $cuit): ?array
    {
        if ($this->isMockMode()) {
            return ['id' => 'mock-cliente-' . substr(hash('sha256', $cuit), 0, 12)];
        }

        try {
            $response = $this->request()->get('/Cliente', ['cuit' => $cuit]);
        } catch (ConnectionException $e) {
            throw new XubioUnavailableException('Xubio GET /Cliente failed: ' . $e->getMessage(), 0, $e);
        }

        $this->logCall('GET', '/Cliente?cuit=' . $cuit, null, $response->json(), $response->status());

        if ($response->status() === 404 || ! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        if (array_is_list($json) && isset($json[0])) {
            return $json[0];
        }

        return $json;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws XubioRejectedException
     * @throws XubioUnavailableException
     */
    public function createCliente(CustomerBillingEntity $entity): array
    {
        if ($this->isMockMode()) {
            return ['id' => 'mock-cliente-' . substr(hash('sha256', $entity->cuit), 0, 12)];
        }

        $payload = [
            'razonSocial'   => $entity->name,
            'cuit'          => $entity->cuit,
            'condicionIva'  => $entity->iva_condition->value ?? null,
        ];

        $body = $this->postJson('/Cliente', $payload, idempotent: false);

        return is_array($body) ? $body : [];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     *
     * @throws XubioTimeoutException     For POSTs that may have side-effected (non-idempotent flag).
     * @throws XubioUnavailableException
     * @throws XubioRejectedException
     */
    private function postJson(string $endpoint, array $payload, bool $idempotent = true): array
    {
        $start = microtime(true);

        try {
            $response = $this->request()->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            // Timeout on POST — for non-idempotent endpoints (invoice/NC emission)
            // do NOT retry. Surface as XubioTimeoutException so the caller can
            // start the reconcile flow.
            $latency = (int) round((microtime(true) - $start) * 1000);
            $this->logCall('POST', $endpoint, $payload, ['error' => $e->getMessage(), 'latency_ms' => $latency], 0);

            if ($idempotent) {
                throw new XubioUnavailableException('Xubio POST timed out: ' . $e->getMessage(), 0, $e);
            }

            throw new XubioTimeoutException('Xubio POST ' . $endpoint . ' timed out: ' . $e->getMessage(), 0, $e);
        }

        $latency = (int) round((microtime(true) - $start) * 1000);
        $this->logCall('POST', $endpoint, $payload, $response->json(), $response->status(), $latency);

        if ($response->status() === 401) {
            // Token expired mid-request — invalidate so the next call refreshes.
            $this->tokenManager->invalidate();

            throw new XubioUnavailableException('Xubio responded 401; token invalidated.');
        }

        if ($response->status() >= 500) {
            throw new XubioUnavailableException(
                sprintf('Xubio POST %s returned %d', $endpoint, $response->status())
            );
        }

        if ($response->status() >= 400) {
            $payloadJson = $response->json();
            throw new XubioRejectedException(
                sprintf('Xubio POST %s rejected with %d', $endpoint, $response->status()),
                is_array($payloadJson) ? $payloadJson : [],
                $response->status(),
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function request(): PendingRequest
    {
        $token = $this->tokenManager->getToken();

        return Http::withToken($token)
            ->baseUrl((string) config('services.xubio.base_url'))
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,mixed>|null $response
     */
    private function logCall(
        string $method,
        string $endpoint,
        ?array $payload,
        ?array $response,
        int $statusCode,
        ?int $latencyMs = null,
    ): void {
        try {
            XubioApiLog::create([
                'request_id'    => (string) Str::uuid(),
                'endpoint'      => $endpoint,
                'method'        => $method,
                'payload_json'  => $payload,
                'response_json' => $response,
                'status_code'   => $statusCode,
                'latency_ms'    => $latencyMs,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging must NEVER break the business flow. Log to system log only.
            \Illuminate\Support\Facades\Log::warning('xubio_api_log insert failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Mock helpers (XUBIO_CLIENT_ID unset → local dev)
    // ------------------------------------------------------------------

    private function mockInvoiceResponse(InvoicePayload $payload): InvoiceResponse
    {
        $fakeId    = 'mock-' . substr(hash('sha256', $payload->externalRef), 0, 16);
        $fakeCae   = '7' . str_pad((string) random_int(1, 9999999999999), 13, '0', STR_PAD_LEFT);
        $fakeNum   = '0001-' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);

        $raw = [
            'id'                => $fakeId,
            'numero'            => $fakeNum,
            'cae'               => $fakeCae,
            'tipoComprobante'   => $payload->voucherType,
            'total'             => $payload->amountArs,
            '_mock'             => true,
        ];

        $this->logCall('POST', '/FacturaVenta', $payload->toArray(), $raw, 200, 5);

        return new InvoiceResponse(
            xubioId:       $fakeId,
            invoiceNumber: $fakeNum,
            cae:           $fakeCae,
            voucherType:   $payload->voucherType,
            amountArs:     $payload->amountArs,
            pdfUrl:        null,
            raw:           $raw,
        );
    }

    private function mockCreditNoteResponse(CreditNotePayload $payload): InvoiceResponse
    {
        $fakeId  = 'mock-nc-' . substr(hash('sha256', $payload->externalRef), 0, 16);
        $fakeCae = '7' . str_pad((string) random_int(1, 9999999999999), 13, '0', STR_PAD_LEFT);
        $fakeNum = '0001-NC-' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);

        $raw = [
            'id'              => $fakeId,
            'numero'          => $fakeNum,
            'cae'             => $fakeCae,
            'tipoComprobante' => $payload->voucherType,
            'total'           => $payload->amountArs,
            '_mock'           => true,
        ];

        $this->logCall('POST', '/NotaCreditoVenta', $payload->toArray(), $raw, 200, 5);

        return new InvoiceResponse(
            xubioId:       $fakeId,
            invoiceNumber: $fakeNum,
            cae:           $fakeCae,
            voucherType:   $payload->voucherType,
            amountArs:     $payload->amountArs,
            pdfUrl:        null,
            raw:           $raw,
        );
    }
}
