<?php

declare(strict_types=1);

namespace App\Services\Xubio\Dto;

/**
 * Payload sent to Xubio POST /FacturaVenta.
 *
 * `externalRef` is the application-side idempotency token used by the
 * reconciliation job (Xubio also stores it as a custom field so we can
 * search by it during ReconcileXubioInvoiceJob).
 *
 * `lineItems` is an array of [{descripcion, cantidad, precio_unitario, ...}].
 */
final class InvoicePayload
{
    /**
     * @param array<int,array<string,mixed>> $lineItems
     */
    public function __construct(
        public readonly string $externalRef,
        public readonly string $clienteId,    // Xubio cliente id (or CUIT lookup result)
        public readonly string $voucherType,  // 'A' | 'B' | 'C'
        public readonly string $currency,     // ARS (always for AFIP) but USD pricing input also possible
        public readonly string $amountArs,    // string-encoded decimal (e.g. "12345.6700")
        public readonly array $lineItems,
        public readonly ?string $exchangeRate = null,  // optional ARS/USD rate snapshot string
        public readonly ?string $notes = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'externalRef'   => $this->externalRef,
            'clienteId'     => $this->clienteId,
            'tipoComprobante' => $this->voucherType,
            'moneda'        => $this->currency,
            'total'         => $this->amountArs,
            'tipoCambio'    => $this->exchangeRate,
            'observaciones' => $this->notes,
            'items'         => $this->lineItems,
        ];
    }
}
