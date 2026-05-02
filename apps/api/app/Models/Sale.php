<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use App\Enums\SaleStatus;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sale — the core transaction entity (§5).
 *
 * State machine: draft → confirmed → delivered → cancelled
 * All transitions are managed via Action classes; this model is read-only
 * regarding status (never call $sale->status = ... directly in controllers).
 *
 * @property string          $id
 * @property string          $customer_id
 * @property string          $seller_id
 * @property string          $zone_id
 * @property SaleStatus      $status
 * @property Carbon          $sale_date
 * @property string          $payment_terms_id
 * @property Carbon|null     $due_date
 * @property string          $currency
 * @property string|null     $exchange_rate_id
 * @property \Brick\Money\Money $total
 * @property string          $total_currency
 * @property bool            $delegated_delivery
 * @property string|null     $delegated_distributor_id
 * @property string|null     $cancellation_reason
 * @property string|null     $cancelled_by
 * @property Carbon|null     $cancelled_at
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 *
 * @property-read Customer          $customer
 * @property-read User              $seller
 * @property-read Zone              $zone
 * @property-read PaymentTerm       $paymentTerms
 * @property-read ExchangeRate|null $exchangeRate
 * @property-read Collection<int,SaleItem>          $items
 * @property-read Collection<int,SalesStatusHistory> $statusHistory
 * @property-read Collection<int,PartialReturn>      $partialReturns
 * @property-read Collection<int,Invoice>            $invoices
 */
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'customer_id',
        'seller_id',
        'zone_id',
        'status',
        'sale_date',
        'payment_terms_id',
        'due_date',
        'currency',
        'exchange_rate_id',
        'total_amount',
        'total_currency',
        'delegated_delivery',
        'delegated_distributor_id',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status'             => SaleStatus::class,
            'sale_date'          => 'date',
            'due_date'           => 'date',
            'delegated_delivery' => 'boolean',
            'cancelled_at'       => 'datetime',
            'total'              => MoneyCast::class . ':total_amount,total_currency',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Customer, Sale> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, Sale> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** @return BelongsTo<Zone, Sale> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** @return BelongsTo<PaymentTerm, Sale> */
    public function paymentTerms(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_terms_id');
    }

    /** @return BelongsTo<ExchangeRate, Sale> */
    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    /** @return BelongsTo<User, Sale> */
    public function delegatedDistributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_distributor_id');
    }

    /** @return BelongsTo<User, Sale> */
    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<SaleItem, Sale> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return HasMany<SalesStatusHistory, Sale> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SalesStatusHistory::class)->orderByDesc('changed_at');
    }

    /** @return HasMany<PartialReturn, Sale> */
    public function partialReturns(): HasMany
    {
        return $this->hasMany(PartialReturn::class);
    }

    /** @return HasMany<Invoice, Sale> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Payment, Sale> — Phase 7 */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isDraft(): bool
    {
        return $this->status === SaleStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->status === SaleStatus::Confirmed;
    }

    public function isDelivered(): bool
    {
        return $this->status === SaleStatus::Delivered;
    }

    public function isCancelled(): bool
    {
        return $this->status === SaleStatus::Cancelled;
    }

    /**
     * Returns true when the sale has at least one invoice that is currently
     * "live" — either successfully emitted or in the reconciling window where
     * Xubio is still being polled. Failed (failed_manual_review) and pending
     * rows that never made it to Xubio do NOT count, so the cancellation /
     * partial-return guards in Phase 5 only block when there is a real
     * AFIP-confirmed comprobante (or one suspected of existing).
     */
    public function hasInvoice(): bool
    {
        return $this->invoices()
            ->whereIn('status', ['success', 'reconciling'])
            ->exists();
    }
}
