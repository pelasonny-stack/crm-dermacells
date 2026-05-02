<?php

declare(strict_types=1);

namespace App\Observers\Dashboards;

use App\Events\Dashboards\PaymentRecorded;
use App\Models\Payment;

/**
 * Phase 14 observer on the Payment model.
 *
 * Fires PaymentRecorded broadcast on new Payment creation.
 * Does NOT rewrite Phase 7 PaymentObserver — it is a separate observer
 * registered independently in AppServiceProvider::boot().
 */
final class PaymentDashboardObserver
{
    public function created(Payment $payment): void
    {
        // Only broadcast for non-reversed payments (reversals fire their own events).
        if (! $payment->getAttribute('reversed')) {
            PaymentRecorded::dispatch($payment);
        }
    }
}
