<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Models\CustomerAccountBalance;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the customer_account_balances running balance (§7.4).
 *
 * Called from PaymentObserver on Payment::created and Payment::updated (reversal).
 *
 * DUAL CURRENCY — NO CONVERSION
 * ==============================
 * ARS payments increment balance_ars ONLY.
 * USD payments increment balance_usd ONLY.
 * Conversion to USD-equivalent for commissions happens at query time in Phase 9.
 *
 * UPSERT STRATEGY
 * ===============
 * Uses Eloquent's updateOrCreate to ensure exactly one row per customer exists.
 * The upsert is executed inside the same DB transaction started by the calling
 * action, so the balance is always consistent with the payment row.
 *
 * ADVANCE PAYMENTS
 * ================
 * Advance payments (is_advance = true) still update the account balance.
 * They contribute to the customer's account balance AND to the Seller's
 * commission accumulator for the month of collection (Phase 9 handles that).
 */
final class AccountBalanceUpdater
{
    /**
     * Increment the account balance when a payment is successfully recorded.
     *
     * Only call this for non-reversed payments. The observer guards this.
     */
    public function increment(Payment $payment): void
    {
        $this->adjustBalance($payment, increment: true);
    }

    /**
     * Decrement the account balance when a payment is reversed (§7.6).
     *
     * Called from the observer when payment.reversed transitions from false → true.
     */
    public function decrement(Payment $payment): void
    {
        $this->adjustBalance($payment, increment: false);
    }

    private function adjustBalance(Payment $payment, bool $increment): void
    {
        $column = match ($payment->amount_currency) {
            'ARS' => 'balance_ars',
            'USD' => 'balance_usd',
            default => throw new \InvalidArgumentException(
                "Unsupported payment currency [{$payment->amount_currency}]. Only ARS and USD are handled."
            ),
        };

        $delta = (float) (string) $payment->amount->getAmount();

        if (! $increment) {
            $delta = -$delta;
        }

        // Upsert: insert row if none exists, otherwise atomically add the delta.
        // Use insertOrIgnore + UPDATE to avoid race conditions on the unique key.
        DB::table('customer_account_balances')->insertOrIgnore([
            'id'          => \Illuminate\Support\Str::uuid()->toString(),
            'customer_id' => $payment->customer_id,
            'balance_ars' => 0,
            'balance_usd' => 0,
            'updated_at'  => now(),
        ]);

        DB::statement(
            "UPDATE customer_account_balances SET {$column} = {$column} + ?, updated_at = now() WHERE customer_id = ?",
            [$delta, $payment->customer_id]
        );
    }
}
