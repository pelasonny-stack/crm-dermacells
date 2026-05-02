<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Domain\Payments\Services\CreditBalanceService;
use App\Enums\UserRole;
use App\Models\CustomerCreditBalance;
use App\Models\Sale;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Manually applies a customer's saldo a favor (credit balance) to a sale — §7.5.
 *
 * RULES
 * =====
 *   - The credit balance must belong to the same customer as the sale.
 *   - The credit balance must be unapplied (applied_to_sale_id IS NULL).
 *   - The application amount must not exceed the credit balance amount.
 *   - The credit balance currency must match the sale's currency.
 *
 * DIRECTOR ONLY per spec §2.4 ("Devolución de cobros → Solo Director").
 * The credit balance application is conceptually the Director managing the
 * customer's financial position.
 */
final class ApplyCreditBalanceAction
{
    public function __construct(
        private readonly CreditBalanceService $creditService,
    ) {}

    /**
     * @throws AuthorizationException   if actor is not a Director
     * @throws \InvalidArgumentException if credit already applied, wrong customer, or amount exceeds balance
     */
    public function execute(
        CustomerCreditBalance $creditBalance,
        Sale $sale,
        Money $amount,
        User $actor,
    ): CustomerCreditBalance {
        if (! $actor->role->isDirector()) {
            throw new AuthorizationException('Only Directors can apply credit balances.');
        }

        return DB::transaction(function () use ($creditBalance, $sale, $amount): CustomerCreditBalance {
            $creditBalance->lockForUpdate()->refresh();

            if ($creditBalance->customer_id !== $sale->customer_id) {
                throw new \InvalidArgumentException(
                    "Credit balance customer [{$creditBalance->customer_id}] does not match sale customer [{$sale->customer_id}]."
                );
            }

            if ($creditBalance->isApplied()) {
                throw new \InvalidArgumentException(
                    "Credit balance [{$creditBalance->id}] is already applied."
                );
            }

            if ($amount->getCurrency()->getCurrencyCode() !== $creditBalance->amount_currency) {
                throw new \InvalidArgumentException(
                    "Cannot apply {$amount->getCurrency()->getCurrencyCode()} credit to a {$creditBalance->amount_currency} balance."
                );
            }

            return $this->creditService->applyToSale($creditBalance, $sale, $amount);
        });
    }
}
