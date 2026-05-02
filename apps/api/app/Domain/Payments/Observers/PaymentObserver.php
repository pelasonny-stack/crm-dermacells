<?php

declare(strict_types=1);

namespace App\Domain\Payments\Observers;

use App\Domain\Payments\Services\AccountBalanceUpdater;
use App\Models\Payment;

/**
 * Observes Payment lifecycle events to keep customer_account_balances in sync.
 *
 * EVENTS HANDLED
 * ==============
 * created  → increment balance (all non-reversed payments, including advances)
 * updated  → decrement balance when reversed transitions false → true (§7.6)
 *
 * DESIGN NOTE
 * ===========
 * The observer is registered in AppServiceProvider::boot() via
 *   Payment::observe(PaymentObserver::class)
 *
 * Both events execute inside the same DB::transaction started by the calling
 * action class, ensuring payment row + balance update are atomic.
 *
 * Advance payments (is_advance = true) still update the account balance — they
 * represent real money received. Commission accumulation is handled separately
 * in Phase 9 by CommissionCalculatorService.
 */
final class PaymentObserver
{
    public function __construct(
        private readonly AccountBalanceUpdater $updater,
    ) {}

    /**
     * Payment successfully created → increment the customer's balance.
     */
    public function created(Payment $payment): void
    {
        // Reversed payments should not increment — sanity guard
        if ($payment->reversed) {
            return;
        }

        $this->updater->increment($payment);
    }

    /**
     * Payment updated → if it was just reversed, decrement the customer's balance.
     */
    public function updated(Payment $payment): void
    {
        // Only act when the reversal was just applied in this update
        $wasReversed = $payment->wasChanged('reversed') && $payment->reversed;

        if ($wasReversed) {
            $this->updater->decrement($payment);
        }
    }
}
