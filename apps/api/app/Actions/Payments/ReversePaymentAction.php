<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\UserRole;
use App\Exceptions\InvoiceNcRequiredException;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a payment — §7.6 (Director only, requires motivo).
 *
 * RULES
 * =====
 *   - Only Directors can reverse payments (§7.6, §2.4 permission table).
 *   - A payment cannot be reversed twice.
 *   - If the payment is associated with an invoice (via its sale), the
 *     controller/caller should check and surface InvoiceNcRequiredException.
 *     This action does NOT block on invoice existence — that check is the
 *     responsibility of the Phase 6 integration layer.
 *     A TODO hook is left here for Phase 6 to inject.
 *
 * AUDIT TRAIL
 * ===========
 * The original payment row is updated with reversed=true + reversed_by + reversed_at +
 * reversal_reason. The row is NEVER deleted. The Auditable trait (via AuditObserver)
 * records the update in audit_log.
 *
 * BALANCE UPDATE
 * ==============
 * PaymentObserver::updated fires after the save, sees reversed changed false→true,
 * and calls AccountBalanceUpdater::decrement.
 */
final class ReversePaymentAction
{
    /**
     * @throws AuthorizationException      if actor is not a Director
     * @throws \InvalidArgumentException   if payment is already reversed
     * @throws InvoiceNcRequiredException  (Phase 6 hook — not yet active)
     */
    public function execute(Payment $payment, User $actor, string $reason): Payment
    {
        if (! $actor->role->isDirector()) {
            throw new AuthorizationException('Only Directors can reverse payments.');
        }

        if ($payment->reversed) {
            throw new \InvalidArgumentException(
                "Payment [{$payment->id}] is already reversed."
            );
        }

        // TODO Phase 6: if the sale has a linked invoice, check with Phase 6 NC flow.
        // The Phase 6 agent should wrap this action and throw InvoiceNcRequiredException
        // before calling execute() when the sale has an invoice without a matching NC.

        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            $payment->lockForUpdate()->refresh();

            // Re-check inside the lock to prevent double-reversal race
            if ($payment->reversed) {
                throw new \InvalidArgumentException(
                    "Payment [{$payment->id}] was reversed concurrently."
                );
            }

            $payment->update([
                'reversed'        => true,
                'reversed_by'     => $actor->id,
                'reversed_at'     => now(),
                'reversal_reason' => $reason,
            ]);
            // PaymentObserver::updated fires here → AccountBalanceUpdater::decrement

            return $payment->refresh();
        });
    }
}
