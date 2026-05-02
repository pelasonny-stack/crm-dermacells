<?php

declare(strict_types=1);

namespace App\Services\Xubio\Dto;

/**
 * Payload for Xubio POST /NotaCreditoVenta.
 *
 * `idComprobanteAsociado` MUST point at the Xubio invoice id of the original
 * factura — required by AFIP and Xubio for valid NC issuance.
 */
final class CreditNotePayload
{
    /**
     * @param array<int,array<string,mixed>> $lineItems
     */
    public function __construct(
        public readonly string $externalRef,
        public readonly string $clienteId,
        public readonly string $idComprobanteAsociado,
        public readonly string $voucherType,
        public readonly string $currency,
        public readonly string $amountArs,
        public readonly array $lineItems,
        public readonly ?string $exchangeRate = null,
        public readonly ?string $notes = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'externalRef'           => $this->externalRef,
            'clienteId'             => $this->clienteId,
            'idComprobanteAsociado' => $this->idComprobanteAsociado,
            'tipoComprobante'       => $this->voucherType,
            'moneda'                => $this->currency,
            'total'                 => $this->amountArs,
            'tipoCambio'            => $this->exchangeRate,
            'observaciones'         => $this->notes,
            'items'                 => $this->lineItems,
        ];
    }
}
