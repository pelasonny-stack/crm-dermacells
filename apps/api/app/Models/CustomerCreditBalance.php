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
 * CustomerCreditBalance — saldo a favor del cliente (§7.5).
 *
 * A credit balance row is created by:
 *   - CreditBalanceService::creditFromCancelledSale (called from CancelSaleAction
 *     and ConfirmPartialReturnAction when there is no invoice).
 *   - Phase 6 will call creditFromCancelledSale after NC emission when invoice exists.
 *
 * APPLICATION
 * ===========
 * A row is "unapplied" when applied_to_sale_id IS NULL.
 * ApplyCreditBalanceAction sets applied_to_sale_id + applied_at when a Director
 * manually imputes the balance to a future sale.
 *
 * Each credit balance row is currency-specific. A client may hold separate ARS
 * and USD credit rows from different cancelled sales.
 *
 * @property string      $id
 * @property string      $customer_id
 * @property Money       $amount
 * @property string      $amount_currency         (raw column)
 * @property string|null $origin_sale_id
 * @property string|null $origin_credit_note_id   (FK added in Phase 6 migration)
 * @property string|null $applied_to_sale_id
 * @property Carbon|null $applied_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Customer  $customer
 * @property-read Sale|null $originSale
 * @property-read Sale|null $appliedToSale
 */
class CustomerCreditBalance extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'customer_id',
        'amount_amount',
        'amount_currency',
        'origin_sale_id',
        'origin_credit_note_id',
        'applied_to_sale_id',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => MoneyCast::class . ':amount_amount,amount_currency',
            'applied_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Customer, CustomerCreditBalance> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Sale, CustomerCreditBalance> */
    public function originSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'origin_sale_id');
    }

    /** @return BelongsTo<Sale, CustomerCreditBalance> */
    public function appliedToSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'applied_to_sale_id');
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    /** Scope: only unapplied (available) credit balances. */
    public function scopeUnapplied(Builder $query): Builder
    {
        return $query->whereNull('applied_to_sale_id');
    }

    /** Scope: filter by currency. */
    public function scopeInCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('amount_currency', $currency);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isApplied(): bool
    {
        return $this->applied_to_sale_id !== null;
    }

    public function currency(): string
    {
        return $this->amount_currency;
    }
}
