<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Models\Customer;
use App\Models\CustomerCreditBalance;
use App\Models\Sale;
use Brick\Money\Money;
use Illuminate\Support\Carbon;

/**
 * Manages saldo a favor (customer credit balances) lifecycle — §7.5.
 *
 * WHEN IS A CREDIT BALANCE CREATED?
 * ==================================
 * 1. Sale cancelled WITH payments AND without invoice:
 *    CancelSaleAction calls creditFromCancelledSale($sale, $totalPaid).
 *
 * 2. Partial return (§5.8) without invoice:
 *    ConfirmPartialReturnAction calls creditFromCancelledSale($sale, $refundAmount).
 *
 * 3. Sale cancelled WITH invoice (Phase 6 flow — InvoiceNcRequiredException):
 *    Phase 6 will call creditFromCancelledSale AFTER the NC is emitted,
 *    passing the CreditNote model. The Phase 6 agent will catch the exception
 *    thrown in CancelSaleAction and hook in here after successful NC emission.
 *
 * CURRENCY
 * ========
 * Each credit balance row is currency-specific. The $amount Money object carries
 * the currency. If a sale had payments in both ARS and USD, the caller must
 * create two separate credits (one per currency).
 */
final class CreditBalanceService
{
    /**
     * Creates a credit balance row for a customer from a cancelled sale or partial return.
     *
     * The `$creditNote` parameter is only populated by Phase 6 when an invoice-backed
     * cancellation triggers a credit note in Xubio. For invoice-free cancellations it
     * remains null.
     *
     * @param  Sale        $sale       The cancelled/partially-returned sale
     * @param  Money       $amount     Amount to credit (in its original currency)
     * @param  string|null $creditNoteId  Phase 6 credit_notes.id (null until Phase 6)
     */
    public function creditFromCancelledSale(
        Sale $sale,
        Money $amount,
        ?string $creditNoteId = null,
    ): CustomerCreditBalance {
        return CustomerCreditBalance::create([
            'customer_id'           => $sale->customer_id,
            'amount_amount'         => (string) $amount->getAmount(),
            'amount_currency'       => $amount->getCurrency()->getCurrencyCode(),
            'origin_sale_id'        => $sale->id,
            'origin_credit_note_id' => $creditNoteId,
            'applied_to_sale_id'    => null,
            'applied_at'            => null,
        ]);
    }

    /**
     * Marks a credit balance row as applied to a specific sale.
     *
     * Called by ApplyCreditBalanceAction when a Director manually imputes
     * saldo a favor to a future sale. The amount must be validated by the
     * caller to not exceed the remaining credit.
     *
     * @throws \InvalidArgumentException if the credit is already applied
     */
    public function applyToSale(
        CustomerCreditBalance $creditBalance,
        Sale $sale,
        Money $amount,
    ): CustomerCreditBalance {
        if ($creditBalance->isApplied()) {
            throw new \InvalidArgumentException(
                "Credit balance [{$creditBalance->id}] is already applied to sale [{$creditBalance->applied_to_sale_id}]."
            );
        }

        // Validate amount does not exceed the credit balance
        if ($amount->isGreaterThan($creditBalance->amount)) {
            throw new \InvalidArgumentException(
                "Cannot apply {$amount} — exceeds available credit of {$creditBalance->amount}."
            );
        }

        $creditBalance->update([
            'applied_to_sale_id' => $sale->id,
            'applied_at'         => Carbon::now(),
        ]);

        return $creditBalance->refresh();
    }

    /**
     * Returns the total unapplied credit balance for a customer in a given currency.
     *
     * Uses the partial index (ccb_unapplied_idx) for efficient aggregation.
     */
    public function availableBalance(Customer $customer, string $currency): Money
    {
        $sum = CustomerCreditBalance::query()
            ->unapplied()
            ->inCurrency($currency)
            ->where('customer_id', $customer->id)
            ->sum('amount_amount');

        return Money::of((string) $sum, $currency);
    }
}
