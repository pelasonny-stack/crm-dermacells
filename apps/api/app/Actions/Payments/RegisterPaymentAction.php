<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Domain\Payments\Services\CashDestinationResolver;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Registers a new payment against a sale — §7.1 – §7.3.
 *
 * VALIDATION
 * ==========
 * Field-level validation (method-specific required fields) is handled upstream
 * by RegisterPaymentRequest + PaymentMethodFieldsConsistent rule. This action
 * receives a pre-validated, typed payload.
 *
 * CASH DESTINATION AUTO-RESOLUTION
 * =================================
 * For cash payments, CashDestinationResolver determines whether the cash goes
 * to the Distributor of the customer's zone or to Dermacells directly (§7.2).
 * This is transparent to the caller.
 *
 * ATOMICITY
 * =========
 * Everything happens inside a single DB::transaction. The PaymentObserver fires
 * AFTER the transaction commits (Eloquent fires observers on the model events
 * within the transaction, but balance update is also within the transaction
 * because the observer runs synchronously during the event dispatch).
 */
final class RegisterPaymentAction
{
    public function __construct(
        private readonly CashDestinationResolver $cashResolver,
    ) {}

    /**
     * @param  array{
     *   sale_id:             string,
     *   payment_method_id:   string,
     *   amount:              Money,
     *   exchange_rate_id:    string|null,
     *   is_advance:          bool,
     *   payment_date:        string,
     *   reference:           string|null,
     *   installments:        int|null,
     *   check_number:        string|null,
     *   check_bank:          string|null,
     *   check_due_date:      string|null,
     * } $payload
     */
    public function execute(array $payload, User $actor): Payment
    {
        return DB::transaction(function () use ($payload, $actor): Payment {
            $sale   = Sale::lockForUpdate()->findOrFail($payload['sale_id']);
            $method = PaymentMethod::findOrFail($payload['payment_method_id']);

            /** @var Customer $customer */
            $customer = Customer::findOrFail($sale->customer_id);

            // Resolve cash destination if applicable
            $cashDestination    = null;
            $cashDistributorId  = null;

            if ($method->isCash()) {
                $decision          = $this->cashResolver->resolveFor($customer);
                $cashDestination   = $decision->destination;
                $cashDistributorId = $decision->distributorId;
            }

            /** @var Money $amount */
            $amount = $payload['amount'];

            return Payment::create([
                'sale_id'                  => $sale->id,
                'customer_id'              => $customer->id,
                'payment_method_id'        => $method->id,
                'amount_amount'            => (string) $amount->getAmount(),
                'amount_currency'          => $amount->getCurrency()->getCurrencyCode(),
                'exchange_rate_id'         => $payload['exchange_rate_id'] ?? null,
                'is_advance'               => $payload['is_advance'] ?? false,
                'payment_date'             => $payload['payment_date'],
                'reference'                => $payload['reference'] ?? null,
                'installments'             => $payload['installments'] ?? null,
                'check_number'             => $payload['check_number'] ?? null,
                'check_bank'               => $payload['check_bank'] ?? null,
                'check_due_date'           => $payload['check_due_date'] ?? null,
                'cash_destination'         => $cashDestination,
                'cash_destination_dist_id' => $cashDistributorId,
                'reversed'                 => false,
                'recorded_by'              => $actor->id,
            ]);
            // PaymentObserver::created fires here → AccountBalanceUpdater::increment
        });
    }
}
