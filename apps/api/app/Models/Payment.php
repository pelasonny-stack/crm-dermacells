<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Payment — a single collected payment against a sale (§7.1 – §7.6).
 *
 * MONEY
 * =====
 * `amount` is a compound MoneyCast over (amount_amount, amount_currency).
 * When you access `$payment->amount` you get a Brick\Money\Money instance.
 *
 * CASH DESTINATION
 * ================
 * For cash payments, `cash_destination` is auto-resolved by CashDestinationResolver
 * based on the customer's zone. It is always NULL for non-cash methods.
 *
 * REVERSAL
 * ========
 * Reversed payments stay in the table (immutable audit trail). The `reversed`
 * flag signals the AccountBalanceUpdater to decrement the account balance.
 * Only Directors can reverse (§7.6).
 *
 * @property string        $id
 * @property string        $sale_id
 * @property string        $customer_id
 * @property string        $payment_method_id
 * @property Money         $amount
 * @property string        $amount_currency    (raw column — use $amount cast instead)
 * @property string|null   $exchange_rate_id
 * @property bool          $is_advance
 * @property string|null   $reference
 * @property int|null      $installments
 * @property string|null   $check_number
 * @property string|null   $check_bank
 * @property Carbon|null   $check_due_date
 * @property string|null   $cash_destination   'dermacells' | 'distributor' | null
 * @property string|null   $cash_destination_dist_id
 * @property Carbon        $payment_date
 * @property bool          $reversed
 * @property string|null   $reversed_by
 * @property Carbon|null   $reversed_at
 * @property string|null   $reversal_reason
 * @property string        $recorded_by
 * @property Carbon        $created_at
 * @property Carbon        $updated_at
 *
 * @property-read Sale          $sale
 * @property-read Customer      $customer
 * @property-read PaymentMethod $paymentMethod
 * @property-read ExchangeRate|null $exchangeRate
 * @property-read User          $recorder
 * @property-read User|null     $reverser
 * @property-read User|null     $cashDistributor
 */
class Payment extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'sale_id',
        'customer_id',
        'payment_method_id',
        'amount_amount',
        'amount_currency',
        'exchange_rate_id',
        'is_advance',
        'reference',
        'installments',
        'check_number',
        'check_bank',
        'check_due_date',
        'cash_destination',
        'cash_destination_dist_id',
        'payment_date',
        'reversed',
        'reversed_by',
        'reversed_at',
        'reversal_reason',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => MoneyCast::class . ':amount_amount,amount_currency',
            'is_advance'     => 'boolean',
            'installments'   => 'integer',
            'check_due_date' => 'date',
            'payment_date'   => 'date',
            'reversed'       => 'boolean',
            'reversed_at'    => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Sale, Payment> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Customer, Payment> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<PaymentMethod, Payment> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<ExchangeRate, Payment> */
    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    /** @return BelongsTo<User, Payment> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, Payment> */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** Distributor user who receives the cash (null when destination=dermacells). */
    /** @return BelongsTo<User, Payment> */
    public function cashDistributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_destination_dist_id');
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    /** Scope: only non-reversed (active) payments. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('reversed', false);
    }

    /** Scope: only advance payments. */
    public function scopeAdvances(Builder $query): Builder
    {
        return $query->where('is_advance', true);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isReversed(): bool
    {
        return $this->reversed;
    }

    public function isCashForDistributor(): bool
    {
        return $this->cash_destination === 'distributor'
            && $this->cash_destination_dist_id !== null;
    }

    public function currency(): string
    {
        return $this->amount_currency;
    }
}
