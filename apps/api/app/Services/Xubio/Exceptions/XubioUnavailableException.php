<?php

declare(strict_types=1);

namespace App\Services\Xubio\Exceptions;

use RuntimeException;

/**
 * Raised when Xubio returns a 5xx, network failure, or any non-business
 * error that is safe to retry. Distinct from XubioRejectedException
 * (4xx business error, do NOT retry) and XubioTimeoutException
 * (timeout — do NOT retry, instead reconcile).
 */
class XubioUnavailableException extends RuntimeException
{
}
