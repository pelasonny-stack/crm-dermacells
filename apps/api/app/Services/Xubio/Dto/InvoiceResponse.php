<?php

declare(strict_types=1);

namespace App\Services\Xubio\Dto;

/**
 * Response from Xubio POST /FacturaVenta and GET /FacturaVenta/{id}.
 *
 * `pdfUrl` may be null when Xubio returns just metadata; in that case
 * the caller fetches the PDF separately via XubioClient::getInvoicePdf().
 */
final class InvoiceResponse
{
    /**
     * @param array<string,mixed> $raw  Original Xubio response for forensic
     *                                  storage (kept on XubioApiLog).
     */
    public function __construct(
        public readonly string $xubioId,
        public readonly string $invoiceNumber,
        public readonly string $cae,
        public readonly string $voucherType,
        public readonly string $amountArs,
        public readonly ?string $pdfUrl = null,
        public readonly array $raw = [],
    ) {
    }
}
