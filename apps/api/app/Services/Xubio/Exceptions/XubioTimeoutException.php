<?php

declare(strict_types=1);

namespace App\Services\Xubio\Exceptions;

use RuntimeException;

/**
 * Raised on HTTP timeout when calling POST endpoints that may have created
 * a comprobante AFIP-side. NEVER retry these — instead transition the local
 * record to 'reconciling' and dispatch ReconcileXubioInvoiceJob.
 */
class XubioTimeoutException extends RuntimeException
{
}
