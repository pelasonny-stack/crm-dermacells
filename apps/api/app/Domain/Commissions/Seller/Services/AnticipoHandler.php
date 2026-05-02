<?php

declare(strict_types=1);

namespace App\Domain\Commissions\Seller\Services;

/**
 * Anticipo (advance payment) handler — §7.3, §12.2.
 *
 * Design decision: NO special storage is required for anticipo payments.
 *
 * When a payment is created with is_advance=true it is persisted in the
 * `payments` table with a payment_date equal to the date the advance was
 * received. The {@see CommissionCalculatorService} queries payments filtered
 * by payment_date within the target calendar month, so anticipos are
 * automatically counted in the correct month without any special lookup or
 * separate accumulator.
 *
 * §12.2 rule (verbatim): "Los anticipos cuentan en el mes en que se cobran."
 * This is satisfied by the natural date filter — the month-of-cobro IS the
 * month the advance was registered, regardless of whether a corresponding
 * sale has been confirmed or delivered.
 *
 * For Distributor settlements (§9.3), anticipos in the Distribuidor's own
 * account are imputed to the saldo-a-rendir at moment of cobro with no
 * differentiated treatment — see DistributorSettlementsController (Phase 8).
 *
 * This class exists as a documentation anchor and DI target for future
 * extension (e.g. if business rules change to require month-override logic).
 * All current anticipo handling is implicit in the date-based payment query
 * executed by CommissionCalculatorService::calculateForMonth().
 *
 * @see \App\Domain\Commissions\Seller\Services\CommissionCalculatorService
 */
final class AnticipoHandler
{
    /**
     * No action needed — anticipos are accounted for implicitly.
     *
     * This no-op method documents the invariant: when a Payment with
     * is_advance=true is created (via Phase 7 PaymentController/Action),
     * the commission accumulation for the receiving month is correct without
     * any side-effect from this handler.
     *
     * Callers MAY invoke this after persisting an advance payment to make
     * the intent explicit in code review and future audits. The method will
     * always return without side effects.
     *
     * @param  string  $paymentId  UUID of the newly created advance payment.
     * @param  string  $sellerId   UUID of the Vendedor who owns the related sale.
     *
     * @return void
     */
    public function onAdvanceCreated(string $paymentId, string $sellerId): void
    {
        // Intentionally empty.
        // The CommissionCalculatorService picks up the payment via its
        // payment_date filter when calculateForMonth() is called for the
        // month in which the advance was received.
        //
        // If future requirements add dedicated anticipo storage (e.g. for
        // real-time dashboard micro-notifications), that logic belongs here.
    }
}
