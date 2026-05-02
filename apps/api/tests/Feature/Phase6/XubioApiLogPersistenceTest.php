<?php

declare(strict_types=1);

use App\Models\XubioApiLog;
use App\Services\Xubio\Dto\InvoicePayload;
use App\Services\Xubio\XubioClient;

/**
 * Every XubioClient HTTP call must be appended to xubio_api_log.
 *
 * In MOCK MODE the client logs the synthetic payload + response so the
 * audit trail is also verified for the local-dev path.
 */
beforeEach(function (): void {
    config()->set('services.xubio.client_id', '');
});

it('logs every emitInvoice call to xubio_api_log', function (): void {
    $countBefore = XubioApiLog::query()->count();

    $client = app(XubioClient::class);

    $payload = new InvoicePayload(
        externalRef: 'sale-test-' . \Illuminate\Support\Str::ulid(),
        clienteId:   'mock-cliente',
        voucherType: 'B',
        currency:    'ARS',
        amountArs:   '12345.67',
        lineItems:   [['descripcion' => 'Test', 'cantidad' => 1, 'precioUnitario' => '12345.67', 'subtotal' => '12345.67']],
    );

    $response = $client->emitInvoice($payload);
    expect($response->cae)->not->toBeEmpty();

    $countAfter = XubioApiLog::query()->count();
    expect($countAfter)->toBeGreaterThan($countBefore);

    $row = XubioApiLog::query()->latest('id')->first();
    expect($row->endpoint)->toBe('/FacturaVenta');
    expect($row->method)->toBe('POST');
    expect($row->status_code)->toBe(200);
    expect($row->payload_json)->toBeArray();
    expect($row->response_json)->toBeArray();
});
